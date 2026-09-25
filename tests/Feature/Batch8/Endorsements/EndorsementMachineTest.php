<?php

declare(strict_types=1);

use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\Endorsements\EndorsementRerater;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Policies\PolicyServicingService;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\PolicyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b8eUser(string $name): User
{
    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b8eIssuedPolicy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['tariff']->update(['rules' => ['base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'sum_insured', 'rate_ppm' => 10000]]]);
    $f['quote']->update(['risk_facts' => ['sum_insured' => 10_000_000]]);
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'risk_facts' => ['sum_insured' => 10_000_000],
        'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-8'], b8eUser('Carrier Desk'));
    $f['maker'] = b8eUser('Servicing Maker');
    $f['checker'] = b8eUser('Servicing Checker');

    return $f;
}

function b8eRequest(array $f, array $data): PolicyTransaction
{
    return app(PolicyServicingService::class)->request($f['policy'], $data + [
        'type' => 'ENDORSEMENT', 'effective_at' => now()->toIso8601String(), 'premium_delta_minor' => 0, 'reason_code' => 'CUSTOMER_REQUEST',
    ], $f['maker'])->refresh();
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-END-001 enforces endorsement type rules: allowed paths, backdating, nil financial effect', function () {
    $f = b8eIssuedPolicy();

    expect(fn () => b8eRequest($f, ['endorsement_type' => 'ADDRESS_CHANGE', 'requested_changes' => ['risk_facts' => ['sum_insured' => 1]]]))
        ->toThrow(ValidationException::class, 'cannot change risk_facts.sum_insured');
    expect(fn () => b8eRequest($f, ['endorsement_type' => 'BENEFICIARY_CHANGE', 'requested_changes' => ['beneficiaries' => []], 'effective_at' => now()->subDays(2)->toIso8601String()]))
        ->toThrow(ValidationException::class);
    expect(fn () => b8eRequest($f, ['endorsement_type' => 'NOPE', 'requested_changes' => ['x' => 1]]))->toThrow(ValidationException::class);

    $t = b8eRequest($f, ['endorsement_type' => 'ADDRESS_CHANGE', 'requested_changes' => ['insured' => ['address' => 'Bonapriso, Douala']], 'premium_delta_minor' => 5000]);
    expect($t->premium_delta_minor)->toBe(0)->and($t->status)->toBe('PENDING_APPROVAL')
        ->and($t->getAttribute('endorsement_type'))->toBe('ADDRESS_CHANGE')->and($t->getAttribute('financial_effect'))->toBe('NIL')
        ->and($f['policy']->refresh()->status)->toBe('ENDORSEMENT_PENDING');
});

it('REQ-END-001 approved avenant writes a new ENDORSEMENT policy_version via the chronology writer and emits policy.endorsement.issued', function () {
    $f = b8eIssuedPolicy();
    $t = b8eRequest($f, ['endorsement_type' => 'ADDRESS_CHANGE', 'requested_changes' => ['insured' => ['address' => 'Akwa']]]);

    expect(fn () => app(PolicyServicingService::class)->approve($t, $f['maker']))->toThrow(ValidationException::class);
    $policy = app(PolicyServicingService::class)->approve($t, $f['checker']);

    expect($policy->status)->toBe('ACTIVE')->and($policy->version)->toBe(2)->and($policy->terms_snapshot['insured']['address'])->toBe('Akwa');
    $versions = DB::table('policy_versions')->where('policy_id', $policy->id)->orderBy('version_no')->get();
    expect($versions)->toHaveCount(2)->and($versions[1]->kind)->toBe('ENDORSEMENT')->and($versions[1]->source_id)->toBe($t->id)
        ->and($versions[0]->superseded_at)->not->toBeNull()->and($versions[1]->terms_hash)->toBe($policy->terms_hash);
    expect($t->refresh()->getAttribute('policy_version_id'))->toBe($versions[1]->id);
    expect(DB::table('outbox_messages')->where('event_name', 'policy.endorsement.issued')->where('aggregate_id', $policy->id)->exists())->toBeTrue();
});

it('REQ-END-001 rerates a sum-insured change pro rata into an additional premium collected before approval (WF-036/037)', function () {
    $f = b8eIssuedPolicy();
    $t = b8eRequest($f, ['endorsement_type' => 'SUM_INSURED_CHANGE', 'requested_changes' => ['risk_facts' => ['sum_insured' => 12_000_000]], 'premium_delta_minor' => 1]);

    $basis = json_decode($t->getRawOriginal('rating_basis'), true);
    expect($basis['method'])->toBe('RERATE_PRO_RATA')->and($basis['annual_delta_minor'])->toBe(20_000)
        ->and($t->premium_delta_minor)->toBe(EndorsementRerater::proRata(20_000, $basis['remaining_days'], $basis['term_days']))
        ->and($t->premium_delta_minor)->toBeGreaterThan(0)
        ->and($t->status)->toBe('PAYMENT_PENDING')->and($t->getAttribute('financial_effect'))->toBe('ADDITIONAL_PREMIUM');
    expect(fn () => app(PolicyServicingService::class)->approve($t, $f['checker']))->toThrow(ValidationException::class);
});

it('REQ-END-001 turns a reduction into a return-premium refund on approval (WF-038)', function () {
    $f = b8eIssuedPolicy();
    $t = b8eRequest($f, ['endorsement_type' => 'SUM_INSURED_CHANGE', 'requested_changes' => ['risk_facts' => ['sum_insured' => 5_000_000]]]);
    expect($t->premium_delta_minor)->toBeLessThan(0)->and($t->status)->toBe('PENDING_APPROVAL');

    $policy = app(PolicyServicingService::class)->approve($t, $f['checker']);
    $refund = DB::table('refunds')->where('id', $t->refresh()->getAttribute('refund_id'))->first();
    expect($refund)->not->toBeNull()->and((int) $refund->amount_minor)->toBe(-$t->premium_delta_minor)
        ->and($refund->reason_code)->toBe('POLICY_ENDORSEMENT')->and($policy->premium_minor)->toBeLessThan(100000);
});

it('REQ-END-001 requires ENDORSE authority for governed types once the carrier configures it; a denial stays recorded', function () {
    $f = b8eIssuedPolicy();
    $limit = fn (User $u) => DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $f['policy']->carrier_id, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => 'ENDORSE', 'max_amount_minor' => 0, 'effective_from' => now()->subDay()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $limit(b8eUser('Someone Else'));
    $t = b8eRequest($f, ['endorsement_type' => 'SUM_INSURED_CHANGE', 'requested_changes' => ['risk_facts' => ['sum_insured' => 9_000_000]]]);

    expect(fn () => app(PolicyServicingService::class)->approve($t, $f['checker']))->toThrow(ValidationException::class, 'ENDORSE authority');
    expect(DB::table('authority_checks')->where('subject_id', $t->id)->value('outcome'))->toBe('DENIED')
        ->and($t->refresh()->status)->toBe('PENDING_APPROVAL');

    $limit($f['checker']);
    app(PolicyServicingService::class)->approve($t, $f['checker']);
    $checkId = $t->refresh()->getAttribute('authority_check_id');
    expect(DB::table('authority_checks')->where('id', $checkId)->value('outcome'))->toBe('ALLOWED');
});

it('REQ-DUP-014 routes both service-request paths through one intake, idempotently, and staff raise the avenant from it', function () {
    $f = b8eIssuedPolicy();
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']) + ['Idempotency-Key' => 'svc-'.Str::random(12)];

    $a = $this->postJson("/api/v1/policies/{$f['policy']->id}/service-requests", ['type' => 'VEHICLE_CHANGE', 'reason' => 'New car bought'], $h)->assertCreated()->json('data.id');
    $b = $this->postJson('/api/v1/mobile/policy-service-requests', ['policy_id' => $f['policy']->id, 'type' => 'VEHICLE_CHANGE', 'reason' => 'New car bought'], $h)->assertCreated();
    expect($b->json('data.id'))->toBe($a)->and($b->headers->get('Deprecation'))->not->toBeNull();
    expect(DB::table('policy_transactions')->where('policy_id', $f['policy']->id)->count())->toBe(1);
    expect(DB::table('policy_transactions')->where('id', $a)->first())->status->toBe('REQUESTED')->endorsement_type->toBe('VEHICLE_CHANGE');

    $t = b8eRequest($f, ['endorsement_type' => 'ADDRESS_CHANGE', 'requested_changes' => ['insured' => ['address' => 'Bastos']], 'service_request_id' => $a]);
    expect($t->getAttribute('service_request_id'))->toBe($a)
        ->and(DB::table('policy_transactions')->where('id', $a)->value('status'))->toBe('CONVERTED');
});
