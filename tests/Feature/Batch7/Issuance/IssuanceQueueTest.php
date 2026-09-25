<?php

declare(strict_types=1);

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\IssuanceQueue\IssuanceTerritoryResolver;
use App\Application\Policies\PaymentIssuanceTrigger;
use App\Models\PolicyIssuanceRequest;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

/** A reconciled, SUCCEEDED payment on a PAYMENT_PENDING proposal (terms currency may differ to force a refusal). */
function b7dPaid(string $termsCurrency = 'XAF'): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => $termsCurrency]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'requested_by' => $f['user']->id]);

    return $f;
}

function b7dOps(array $f, array $perms = ['policies.issuance_queue.view', 'policies.issuance_queue.manage', 'policies.issuance_queue.resolve']): App\Models\User
{
    return makeAuthTestUser($f['tenant'], $perms, 'OPERATIONS_MANAGER');
}

function b7dNotifications(array $f, string $title): int
{
    return UserNotification::where('user_id', $f['user']->id)->where('title', $title)->count();
}

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('records a refused automatic issuance request in the queue instead of swallowing it, and tells the customer once', function () {
    $f = b7dPaid('EUR'); // payment XAF ≠ terms EUR → PolicyIssuanceService refuses

    expect(app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']))->toBeNull();

    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    expect($ex->kind)->toBe('ISSUANCE_REQUEST_FAILED')->and($ex->reason_code)->toBe('ISSUANCE_REQUEST_REFUSED')
        ->and($ex->status)->toBe('OPEN')->and($ex->attempts)->toBe(1)->and($ex->territory)->toBe('CM')
        ->and($ex->premium_cover['outcome'])->toBe('UNDETERMINED')
        ->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
    expect(b7dNotifications($f, 'Payment received — policy issuance delayed'))->toBe(1);

    // A replay bumps the attempt count on the same row; the customer is not told twice.
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);
    expect(IssuanceException::where('proposal_id', $f['proposal']->id)->count())->toBe(1)
        ->and($ex->refresh()->attempts)->toBe(2)
        ->and(b7dNotifications($f, 'Payment received — policy issuance delayed'))->toBe(1)
        ->and(DB::table('issuance_exception_events')->where('issuance_exception_id', $ex->id)->pluck('action')->all())->toBe(['RECORDED', 'FAILED_AGAIN']);
});

it('lists, retries, escalates and resolves exceptions through the permissioned API', function () {
    $f = b7dPaid('EUR');
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    $h = tenantHeaderFor($f['tenant']);

    Passport::actingAs(makeAuthTestUser($f['tenant'], ['policies.view']));
    $this->getJson('/api/v1/issuance-exceptions', $h)->assertForbidden();

    $ops = b7dOps($f);
    Passport::actingAs($ops);
    $this->getJson('/api/v1/issuance-exceptions', $h)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ex->id);
    $this->getJson("/api/v1/issuance-exceptions/{$ex->id}", $h)->assertOk()->assertJsonPath('data.events.0.action', 'RECORDED');

    // Another tenant cannot see it.
    $other = makeAuthTestTenant('b7d-other');
    Passport::actingAs(makeAuthTestUser($other, ['policies.issuance_queue.view']));
    $this->getJson('/api/v1/issuance-exceptions', tenantHeaderFor($other))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/issuance-exceptions/{$ex->id}", tenantHeaderFor($other))->assertNotFound();

    Passport::actingAs($ops);
    // Manual close is refused while nothing was issued: a paid, un-issued proposal is refunded or retried.
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/resolve", ['resolution' => 'RESOLVED_MANUALLY', 'notes' => 'done'], $h)->assertStatus(422);
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/escalate", ['reason' => 'Carrier desk to check premium', 'escalated_to' => $ops->id], $h)
        ->assertOk()->assertJsonPath('data.status', 'ESCALATED');

    // Retry still fails → attempts grow, stays escalated.
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/retry", [], $h)->assertOk()->assertJsonPath('data.attempts', 2)->assertJsonPath('data.status', 'ESCALATED');

    // Fix the data, retry → the issuance request opens and the exception closes.
    $f['proposal']->update(['terms_snapshot' => [...$f['proposal']->terms_snapshot, 'currency' => 'XAF']]);
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/retry", [], $h)->assertOk()
        ->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.resolution', 'ISSUANCE_REQUESTED');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->sole();
    expect($request->status)->toBe('CARRIER_REVIEW')->and($ex->refresh()->policy_issuance_request_id)->toBe($request->id)
        ->and(b7dNotifications($f, 'Payment received — issuance in progress'))->toBe(1);
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/retry", [], $h)->assertStatus(422); // already resolved
});

it('resolves an exception as refund requested with notes', function () {
    $f = b7dPaid('EUR');
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    Passport::actingAs(b7dOps($f, ['policies.issuance_queue.view', 'policies.issuance_queue.manage']));
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/resolve", ['resolution' => 'REFUND_REQUESTED', 'notes' => 'x'], tenantHeaderFor($f['tenant']))->assertForbidden();

    Passport::actingAs(b7dOps($f));
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/resolve", ['resolution' => 'REFUND_REQUESTED', 'notes' => 'Carrier declined; refund the customer'], tenantHeaderFor($f['tenant']))
        ->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.resolution', 'REFUND_REQUESTED');
});

it('blocks automatic issuance when the premium-to-cover rule engine says NO_COVER (owner decision 17)', function () {
    $f = b7dPaid();
    DB::table('premium_cover_rules')->insert(['id' => (string) Str::uuid(), 'code' => 'B7D_NO_COVER', 'name' => 'Test', 'product_id' => $f['product']->id,
        'premium_statuses' => json_encode(['PAID']), 'activation_rule' => json_encode(true), 'outcome' => 'NO_COVER', 'effective_from' => now()->subYear()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);

    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    expect($ex->kind)->toBe('ISSUANCE_BLOCKED')->and($ex->reason_code)->toBe('PREMIUM_COVER_NO_COVER')
        ->and($ex->premium_cover['rule']['code'])->toBe('B7D_NO_COVER')
        ->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('blocks automatic issuance while an ISSUANCE_REQUIRED document is not accepted (owner decision 31)', function () {
    $this->seed(\Database\Seeders\DocumentCatalogueSeeder::class);
    $f = b7dPaid();
    // The motor TPL catalogue row makes VEHICLE_REGISTRATION_CARD an ISSUANCE_REQUIRED upload.
    DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $f['product']->id, 'kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_TPL',
        'variant_code' => '', 'status' => 'ACTIVE', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);

    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    expect($ex->reason_code)->toBe('DOCUMENTS_NOT_ACCEPTED')->and($ex->blockers)->toContain('DOCUMENT_MISSING:VEHICLE_REGISTRATION_CARD')
        ->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('resolves the issuance territory from risk data, then the carrier, then the tenant — never a hard-coded CM', function () {
    $f = b7dPaid();
    $resolver = app(IssuanceTerritoryResolver::class);
    $f['tenant']->update(['country_code' => 'SN']);
    expect($resolver->resolve($f['proposal']->fresh())['territory'])->toBe('SN');

    $f['carrier']->update(['country_code' => 'ga']);
    expect($resolver->resolve($f['proposal']->fresh()))->toBe(['territory' => 'GA', 'source' => 'CARRIER']);

    $f['quote']->update(['risk_facts' => ['territory' => 'CEMAC']]);
    expect($resolver->resolve($f['proposal']->fresh()))->toBe(['territory' => 'CEMAC', 'source' => 'RISK_FACTS.territory']);

    expect(file_get_contents(app_path('Application/Policies/PaymentIssuanceTrigger.php')))->not->toContain("'territory' => 'CM'");

    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);
    expect(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeTrue()
        ->and(IssuanceException::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('scans for paid-not-issued payments and queues them once', function () {
    $f = b7dPaid();
    $f['payment']->update(['reconciled_at' => now()->subHours(2)]);
    $h = tenantHeaderFor($f['tenant']);
    Passport::actingAs(b7dOps($f));

    $this->postJson('/api/v1/issuance-exceptions/scan', [], $h)->assertOk()->assertJsonPath('meta.recorded', 1)
        ->assertJsonPath('data.0.kind', 'PAID_NOT_ISSUED')->assertJsonPath('data.0.reason_code', 'NO_ISSUANCE_REQUEST');
    $this->postJson('/api/v1/issuance-exceptions/scan', [], $h)->assertOk()->assertJsonPath('meta.recorded', 0);
    expect(b7dNotifications($f, 'Payment received — policy issuance delayed'))->toBe(1);

    // Retrying the scanned payment opens the request and closes the row.
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/retry", [], $h)->assertOk()->assertJsonPath('data.status', 'RESOLVED');
});

it('queues a paid proposal whose underwriting case has no approving decision (issuability gate), and issues once approved', function () {
    $f = b7dPaid();
    $case = App\Models\UnderwritingCase::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'QUEUED',
        'priority' => 'NORMAL', 'referral_reasons' => [], 'decision_due_at' => now()->addDays(2)]);

    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']);
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    expect($ex->reason_code)->toBe('NOT_ISSUABLE')->and($ex->blockers)->toContain('UNDERWRITING_NOT_APPROVED');

    // STP decision recorded → the next attempt issues and closes the row.
    App\Models\UnderwritingDecision::create(['underwriting_case_id' => $case->id, 'decision' => 'APPROVED', 'reason_code' => 'STRAIGHT_THROUGH', 'notes' => 'auto',
        'conditions' => [], 'decided_by' => $f['user']->id, 'decided_at' => now()]);
    app(PaymentIssuanceTrigger::class)->attempt($f['payment']);
    expect($ex->refresh()->status)->toBe('RESOLVED')->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeTrue();
});
