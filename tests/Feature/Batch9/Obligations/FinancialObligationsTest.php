<?php

declare(strict_types=1);

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\Obligations\PolicyPremiumObligations;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/** @return array{policy: Policy, payment: PaymentIntentRecord, tenant: Tenant} */
function b91Issue(?array $coverTerms = null, int $paid = 100000): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'cover_terms' => $coverTerms, 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
    ]]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id, 'amount_minor' => $paid]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $payment->provider_reference, 'amount_minor' => $paid, 'currency' => 'XAF', 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $policy = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-91'], b91User());

    return ['policy' => $policy, 'payment' => $payment->refresh(), 'tenant' => $f['tenant']];
}

function b91User(): User
{
    return User::create(['full_name' => 'Fin '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b91Staff(Tenant $t, array $perms): User
{
    $u = b91User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'FIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'FIN_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function b91Tenant(): Tenant
{
    $slug = 'b91-'.Str::lower(Str::random(8));

    return Tenant::create(['type' => 'BROKER', 'legal_name' => 'Broker '.$slug, 'slug' => $slug, 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function b91Quarterly(): array
{
    return ['instalment_plan' => 'QUARTERLY', 'duration' => ['unit' => 'MONTH', 'value' => 12], 'effective_rule' => 'IMMEDIATE', 'schedule' => [
        ['sequence' => 1, 'due' => 'AT_BIND', 'amount_minor' => 25000, 'fee_minor' => 0],
        ['sequence' => 2, 'due' => '+3M', 'amount_minor' => 25500, 'fee_minor' => 500],
        ['sequence' => 3, 'due' => '+6M', 'amount_minor' => 25500, 'fee_minor' => 500],
        ['sequence' => 4, 'due' => '+9M', 'amount_minor' => 25500, 'fee_minor' => 500],
    ]];
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-OBL-001 creates, partially and fully settles an obligation idempotently with history, outbox and aging', function () {
    $t = b91Tenant();
    $svc = app(ObligationService::class);
    $o = $svc->create(['tenant_id' => $t->id, 'kind' => 'RECEIVABLE', 'type' => 'FEE', 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => 10000, 'due_at' => now()->subDays(45)]);
    expect($o->status)->toBe('OPEN')->and((int) $o->outstanding_minor)->toBe(10000)
        ->and(ObligationService::bucket($o->due_at))->toBe('31_60')
        ->and($svc->aging($t->id)['XAF']['31_60'])->toBe(10000);

    $o = $svc->settle($o->id, 4000, 'ref-1');
    expect($o->status)->toBe('PARTIALLY_SETTLED')->and($svc->outstanding($o->id))->toBe(6000);
    $svc->settle($o->id, 4000, 'ref-1'); // replay
    expect($svc->outstanding($o->id))->toBe(6000);
    $o = $svc->settle($o->id, 9000, 'ref-2'); // over-payment is capped
    expect($o->status)->toBe('SETTLED')->and((int) $o->outstanding_minor)->toBe(0)
        ->and(DB::table('financial_obligation_events')->where('financial_obligation_id', $o->id)->pluck('event_type')->all())->toBe(['CREATED', 'SETTLEMENT', 'SETTLEMENT'])
        ->and(DB::table('outbox_messages')->where('event_name', 'finance.obligation.settled')->count())->toBe(1);
    $o = $svc->unsettle($o->id, 3000, 'rev-1');
    $svc->unsettle($o->id, 3000, 'rev-1'); // replay
    expect($o->status)->toBe('PARTIALLY_SETTLED')->and($svc->outstanding($o->id))->toBe(3000)
        ->and(DB::table('outbox_messages')->where('event_name', 'finance.obligation.reopened')->count())->toBe(1);
    $svc->settle($o->id, 3000, 'ref-3');
    expect(fn () => $svc->writeOff($o->id, 'x'))->toThrow(ValidationException::class);
    $link = app(\App\Application\Finance\Obligations\ObligationServiceRefundLink::class);
    $refundId = (string) Str::uuid();
    $rid = $link->openRefundPayable($t->id, $refundId, 5000, 'XAF');
    expect($link->settleRefund($refundId, 5000, 'PAYOUT-1'))->toBe($rid)
        ->and(DB::table('financial_obligations')->where('id', $rid)->value('status'))->toBe('SETTLED');
    expect(fn () => $svc->create(['tenant_id' => $t->id, 'kind' => 'RECEIVABLE', 'type' => 'FEE', 'source_type' => 't', 'source_id' => (string) Str::uuid(), 'currency' => 'XAF', 'amount_minor' => 0, 'due_at' => now()]))
        ->toThrow(ValidationException::class);
});

it('REQ-OBL-001 a single-premium policy gets one PREMIUM obligation settled by the bind payment at issuance', function () {
    ['policy' => $policy, 'payment' => $payment] = b91Issue();
    $obligations = app(PolicyPremiumObligations::class)->forPolicy($policy->id);

    expect($obligations)->toHaveCount(1)
        ->and($obligations[0]->type)->toBe('PREMIUM')->and($obligations[0]->status)->toBe('SETTLED')
        ->and((int) $obligations[0]->amount_minor)->toBe(100000)->and($obligations[0]->debtor_id)->toBe($policy->party_id)
        ->and(DB::table('policy_premium_instalments')->where('policy_id', $policy->id)->count())->toBe(0);
    // Reconciliation replay stays idempotent.
    app(PolicyPremiumObligations::class)->settlePayment($payment);
    expect(DB::table('financial_obligation_events')->where('financial_obligation_id', $obligations[0]->id)->where('event_type', 'SETTLEMENT')->count())->toBe(1);
});

it('REQ-PAY-006 generates instalments + obligations from the cover-terms schedule; later payments settle them', function () {
    ['policy' => $policy, 'tenant' => $tenant] = b91Issue(b91Quarterly(), 25000);
    $start = CarbonImmutable::parse($policy->coverage_starts_at);
    $rows = DB::table('policy_premium_instalments')->where('policy_id', $policy->id)->orderBy('sequence')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->all())->toBe(['PAID', 'DUE', 'DUE', 'DUE'])
        ->and($rows[1]->due_date)->toBe($start->addMonthsNoOverflow(3)->toDateString())
        ->and($rows->whereNull('financial_obligation_id')->count())->toBe(0);
    $o2 = DB::table('financial_obligations')->where('id', $rows[1]->financial_obligation_id)->first();
    expect($o2->source_type)->toBe('policy')->and($o2->source_id)->toBe($policy->id)->and($o2->source_reference)->toBe('INSTALMENT:2')
        ->and($o2->type)->toBe('INSTALMENT')->and((int) $o2->amount_minor)->toBe(25500)->and($o2->status)->toBe('OPEN');
    expect(DB::table('financial_obligations')->where('id', $rows[0]->financial_obligation_id)->value('status'))->toBe('SETTLED');

    // Second instalment paid through a payment intent raised against its obligation → webhook reconciles and settles both.
    $p2 = makeMobileTestPayment($policy->proposal, $tenant, ['status' => 'PENDING_CUSTOMER', 'amount_minor' => 25500]);
    DB::table('payment_intents')->where('id', $p2->id)->update(['financial_obligation_id' => $o2->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $p2->provider_reference, 'amount_minor' => 25500, 'currency' => 'XAF', 'status' => 'SUCCEEDED',
    ], 'sig');

    expect(DB::table('financial_obligations')->where('id', $o2->id)->value('status'))->toBe('SETTLED')
        ->and(DB::table('policy_premium_instalments')->where('id', $rows[1]->id)->value('status'))->toBe('PAID');
    // Generation is idempotent.
    app(PolicyPremiumObligations::class)->generate($policy);
    expect(DB::table('financial_obligations')->where('policy_id', $policy->id)->count())->toBe(4);
});

it('REQ-OBL-001 list / show / aging APIs enforce permissions and tenant isolation', function () {
    ['policy' => $policy, 'tenant' => $tenant] = b91Issue(b91Quarterly(), 25000);
    $h = ['X-Tenant-Id' => $tenant->id];
    $id = DB::table('financial_obligations')->where('policy_id', $policy->id)->where('status', 'OPEN')->value('id');

    Passport::actingAs(b91Staff($tenant, ['finance.obligations.view']));
    $this->getJson('/api/v1/finance/obligations?policy_id='.$policy->id, $h)->assertOk()->assertJsonPath('meta.total', 4);
    $this->getJson("/api/v1/finance/obligations/{$id}", $h)->assertOk()->assertJsonPath('data.events.0.event_type', 'CREATED')->assertJsonPath('data.aging_bucket', 'CURRENT');
    $this->getJson('/api/v1/finance/obligations/aging', $h)->assertOk()->assertJsonPath('data.XAF.CURRENT', 76500);
    $this->getJson("/api/v1/finance/policies/{$policy->id}/instalments", $h)->assertOk()->assertJsonCount(4, 'data');
    $this->postJson("/api/v1/finance/obligations/{$id}/write-off", ['reason' => 'x'], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

    Passport::actingAs(b91Staff($tenant, ['finance.obligations.view', 'finance.obligations.manage']));
    $this->postJson("/api/v1/finance/obligations/{$id}/write-off", ['reason' => 'Uncollectable'], $h + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.status', 'WRITTEN_OFF');

    $other = b91Tenant();
    Passport::actingAs(b91Staff($other, ['finance.obligations.view']));
    $this->getJson("/api/v1/finance/obligations/{$id}", ['X-Tenant-Id' => $other->id])->assertNotFound();
    $this->getJson('/api/v1/finance/obligations', ['X-Tenant-Id' => $other->id])->assertOk()->assertJsonPath('meta.total', 0);
});

it('REQ-OBL-001 backfill creates a settled PREMIUM obligation for legacy issued policies; dry-run writes nothing', function () {
    ['policy' => $policy] = b91Issue();
    DB::table('financial_obligation_events')->delete();
    DB::table('financial_obligations')->delete();

    $this->artisan('finance:backfill-obligations', ['--dry-run' => true])->expectsOutputToContain('1 policies')->assertSuccessful();
    expect(DB::table('financial_obligations')->count())->toBe(0);
    $this->artisan('finance:backfill-obligations')->assertSuccessful();
    $this->artisan('finance:backfill-obligations')->assertSuccessful();
    $rows = DB::table('financial_obligations')->where('policy_id', $policy->id)->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->status)->toBe('SETTLED')->and($rows[0]->type)->toBe('PREMIUM');
});
