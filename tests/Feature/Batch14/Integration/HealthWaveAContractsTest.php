<?php

declare(strict_types=1);

/**
 * WA-FIX — Wave A health contracts end to end: E2 eligibility keys read by E3/E4, E3 → E5 reserve/release (holder
 * preauth:<id>), E4 → E3 guarantee-of-payment verification + consumption, E4 → E5 consumption drawing the preauth
 * reservation (claim marked CASHLESS so the reimbursement hook skips it), provider-side actions scoped by ProviderScope.
 */

use App\Application\Health\Benefits\BenefitAccumulator;
use App\Application\Health\Benefits\BenefitSchedule;
use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const WAFIX_PERMS = ['health.preauth.view', 'health.preauth.request', 'health.preauth.review', 'health.preauth.approve',
    'health.provider_claims.view', 'health.provider_claims.capture', 'health.provider_claims.adjudicate', 'health.provider_claims.approve_payment', 'health.provider_claims.dispute'];

function wafixStaff(string $carrierId, ?string $partyId = null): User
{
    $u = makeAuthTestUser(test()->tenant, WAFIX_PERMS, 'WAFIX_'.Str::random(4));
    if ($partyId) {
        $u->forceFill(['party_id' => $partyId])->save();
    }
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => PreauthLifecycle::authorityType(), 'max_amount_minor' => 10_000_000, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $u;
}

function wafixProvider(string $name): object
{
    $reg = app(ProviderRegistry::class);
    $p = $reg->register(['category' => 'HEALTH', 'name' => $name.' '.Str::random(4), 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($p->id, $to, null, null, null);
    }

    return $reg->find($p->id);
}

/** Staff-captured, approved outpatient GOP for the consultation (insurer share 8 000). */
function wafixApprovedGop(): array
{
    Passport::actingAs(test()->staff, [], 'api');
    $pa = test()->postJson('/api/v1/health/preauthorizations', ['request_type' => 'OUTPATIENT', 'policy_id' => test()->policy->id, 'provider_id' => test()->provider->id,
        'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'], 'lines' => [['service_code' => 'CONS_WF', 'quantity' => 1]]], test()->h)
        ->assertCreated()->json('data');
    Passport::actingAs(test()->maker, [], 'api');
    test()->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'APPROVED', 'valid_until' => now()->addDays(5)->toDateString()], test()->h)->assertOk();
    Passport::actingAs(test()->checker, [], 'api');

    return test()->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], test()->h)->assertOk()->assertJsonPath('data.status', 'APPROVED')->json('data');
}

function wafixClaim(array $over = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/health/provider-claims', array_merge([
        'provider_id' => test()->provider->id, 'contract_id' => test()->contract, 'invoice_reference' => 'INV-'.Str::random(6), 'service_date' => now()->toDateString(),
        'policy_id' => test()->policy->id, 'member_party_id' => test()->party->id, 'lines' => [['medical_service_id' => test()->cons, 'unit_price_minor' => 10000]],
    ], $over), test()->h);
}

function wafixOpdRemaining(): ?int
{
    return app(BenefitAccumulator::class)->remaining(test()->tenant->id, ['member_ref' => test()->party->id, 'policy_id' => test()->policy->id], 'OPD', Carbon::now())['remaining_minor'];
}

beforeEach(function () {
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant'];
    $this->party = $f['party'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->policy = makeMobileTestPolicy($f['proposal'], $this->tenant, $f['carrier']->id, $f['party']->id, ['currency' => 'XAF', 'coverage_starts_at' => now()->subMonth()]);
    app(TenantContext::class)->set($this->tenant->id);
    $this->staff = wafixStaff($f['carrier']->id);
    $this->maker = wafixStaff($f['carrier']->id);
    $this->checker = wafixStaff($f['carrier']->id);

    $this->provider = wafixProvider('Clinique WF');
    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_WF', 'name' => 'Consultation', 'category_code' => 'OUTPATIENT'])->id;
    $network = $net->createNetwork($this->tenant->id, ['code' => 'WFNET', 'name' => 'WF network', 'network_type_code' => 'PPN', 'category' => 'HEALTH'], null);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->provider->id, 'contract_number' => 'WF-001', 'effective_from' => now()->subMonth()->toDateString()], null)->id;
    $t = $net->draftTariff($this->tenant->id, $this->contract, now()->subMonth()->toDateString(), 'XAF', [
        ['medical_service_id' => $this->cons, 'price_minor' => 12000, 'contracted_price_minor' => 10000, 'copay_minor' => 2000, 'insurer_share_percent' => 100],
    ], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $t->id, (string) Str::uuid());

    // E2 benefit schedule mapping: OUTPATIENT services → coverage HLT_OPD / benefit OPD; E5 schedule OPD with a 100 000 limit.
    DB::table('health_benefit_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'service_category_code' => 'OUTPATIENT',
        'coverage_code' => 'HLT_OPD', 'benefit_code' => 'OPD', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    app(BenefitSchedule::class)->create(['insurance_product_id' => $f['product']->id, 'benefit_code' => 'OPD', 'period_limit_minor' => 100_000, 'effective_from' => now()->subYear()->toDateString()]);
});

it('WA-FIX: E3 reads E2 keys and reserves on E5 under holder preauth:<id>; cancel releases it', function () {
    $pa = wafixApprovedGop();
    expect($pa['lines'][0]['eligibility'])->toHaveKeys(['outcome', 'eligible', 'benefit_code', 'coverage_code'])
        ->and($pa['lines'][0]['eligibility']['benefit_code'])->toBe('OPD')
        ->and($pa['benefit_reservations'][0])->toMatchArray(['benefit_code' => 'OPD', 'status' => 'RESERVED', 'amount_minor' => 8000, 'holder' => 'preauth:'.$pa['id']])
        ->and(wafixOpdRemaining())->toBe(92_000)
        ->and(DB::table('health_benefit_movements')->where('holder_ref', 'preauth:'.$pa['id'])->where('movement_type', 'RESERVE')->sum('amount_minor'))->toEqual(8000);

    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/cancel", ['reason' => 'Not needed'], $this->h)->assertOk()->assertJsonPath('data.benefit_reservations.0.status', 'RELEASED');
    expect(wafixOpdRemaining())->toBe(100_000);

    // Blocking E2 outcome (waiting period) → only a decline.
    DB::table('health_benefit_rules')->update(['waiting_period_days' => 365]);
    Passport::actingAs($this->staff, [], 'api');
    $this->postJson('/api/v1/health/preauthorizations', ['request_type' => 'OUTPATIENT', 'policy_id' => $this->policy->id, 'provider_id' => $this->provider->id,
        'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'], 'lines' => [['service_code' => 'CONS_WF', 'quantity' => 1]]], $this->h)
        ->assertCreated()->assertJsonPath('data.eligible', false)->assertJsonPath('data.lines.0.eligibility.outcome', 'WAITING_PERIOD');
});

it('WA-FIX: E4 verifies the GOP, consumes it and the E5 reservation; the member Claim is CASHLESS; a second invoice finds it exhausted', function () {
    $pa = wafixApprovedGop();
    Passport::actingAs($this->staff, [], 'api');
    $id = wafixClaim(['preauth_id' => $pa['id']])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/health/provider-claims/{$id}/submit", [], $this->h)->assertOk()->assertJsonPath('data.preauth_verified', true)->assertJsonPath('data.preauth_check', 'VERIFIED');
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/provider-claims/{$id}/review", [], $this->h)->assertOk();
    $c = $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", [], $this->h)->assertOk()->assertJsonPath('data.status', 'APPROVED')->json('data');
    expect($c['lines'][0])->toMatchArray(['benefit_code' => 'OPD', 'insurer_share_minor' => 8000])->and($c['lines'][0]['eligibility_outcome'])->not->toBeIn(PreauthorizationService::BLOCKING_OUTCOMES);
    $this->postJson("/api/v1/health/provider-claims/{$id}/payable", [], $this->h)->assertOk()->assertJsonPath('data.preauth_consumed_minor', 8000);

    expect((int) DB::table('health_preauthorizations')->where('id', $pa['id'])->value('consumed_amount_minor'))->toBe(8000)
        // consumption drew the reservation: nothing double counted, nothing still reserved
        ->and(wafixOpdRemaining())->toBe(92_000)
        ->and((int) DB::table('health_benefit_accumulators')->where('subject_ref', $this->party->id)->value('reserved_minor'))->toBe(0)
        ->and((int) DB::table('health_benefit_accumulators')->where('subject_ref', $this->party->id)->value('consumed_minor'))->toBe(8000)
        ->and(DB::table('health_benefit_movements')->where('holder_ref', 'preauth:'.$pa['id'])->where('movement_type', 'CONSUME')->exists())->toBeTrue();
    $claim = DB::table('claims')->find(DB::table('health_provider_claims')->where('id', $id)->value('claim_id'));
    expect(json_decode($claim->loss_details, true)['health']['channel'])->toBe('CASHLESS');

    // Same GOP again → exhausted: the claim is still adjudicable, without the guarantee.
    Passport::actingAs($this->staff, [], 'api');
    $id2 = wafixClaim(['preauth_id' => $pa['id']])->json('data.id');
    $this->postJson("/api/v1/health/provider-claims/{$id2}/submit", [], $this->h)->assertOk()->assertJsonPath('data.preauth_verified', false)->assertJsonPath('data.preauth_check', 'GOP_EXHAUSTED');

    // Validity window, provider and unknown id are checked.
    $svc = app(PreauthorizationService::class);
    expect($svc->guaranteeFor($this->tenant->id, $pa['id'], $this->provider->id, $this->policy->id, now()->addDays(10)->toDateString())['reason'])->toBe('GOP_OUTSIDE_VALIDITY')
        ->and($svc->guaranteeFor($this->tenant->id, $pa['id'], (string) Str::uuid(), $this->policy->id, now()->toDateString())['reason'])->toBe('GOP_OTHER_PROVIDER')
        ->and($svc->guaranteeFor($this->tenant->id, (string) Str::uuid(), $this->provider->id, null, now()->toDateString())['reason'])->toBe('GOP_NOT_FOUND');
});

it('WA-FIX: provider-side E3/E4 actions need ProviderScope::actsFor for provider users; back-office staff keep working', function () {
    $other = wafixProvider('Other clinic');
    $outsider = wafixStaff($this->policy->carrier_id, $other->party_id);   // acts for another provider
    $own = wafixStaff($this->policy->carrier_id, $this->provider->party_id); // acts for this provider

    Passport::actingAs($outsider, [], 'api');
    wafixClaim()->assertStatus(403)->assertJsonPath('code', 'PROVIDER_SCOPE');
    $this->postJson('/api/v1/health/preauthorizations', ['request_type' => 'OUTPATIENT', 'policy_id' => $this->policy->id, 'provider_id' => $this->provider->id,
        'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'], 'lines' => [['service_code' => 'CONS_WF', 'quantity' => 1]]], $this->h)
        ->assertStatus(403)->assertJsonPath('code', 'PROVIDER_SCOPE');

    Passport::actingAs($own, [], 'api');
    $id = wafixClaim()->assertCreated()->json('data.id');
    Passport::actingAs($outsider, [], 'api');
    $this->postJson("/api/v1/health/provider-claims/{$id}/submit", [], $this->h)->assertStatus(403);
    Passport::actingAs($this->staff, [], 'api'); // back office, no provider
    $this->postJson("/api/v1/health/provider-claims/{$id}/submit", [], $this->h)->assertOk();
});
