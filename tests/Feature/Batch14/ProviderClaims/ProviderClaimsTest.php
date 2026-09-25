<?php

declare(strict_types=1);

/** Agent E4 — REQ-HLT-003 provider claims (cashless billing): tariff pricing, EOB, member Claim link, disputes, settlement, statements. */

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const HPC_PERMS = ['health.provider_claims.view', 'health.provider_claims.capture', 'health.provider_claims.adjudicate', 'health.provider_claims.approve_payment',
    'health.provider_claims.dispute', 'health.provider_settlements.manage', 'health.provider_settlements.pay'];

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant'];
    $this->party = $f['party'];
    $this->policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->clerk = makeAuthTestUser($this->tenant, HPC_PERMS, 'HPC_CLERK');
    $this->adjuster = makeAuthTestUser($this->tenant, HPC_PERMS, 'HPC_ADJ');

    $this->provider = app(ProviderRegistry::class)->register(['category' => 'HEALTH', 'name' => 'Clinique E4 '.Str::random(4), 'provider_type_code' => 'CLINIC'], null);
    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_E4', 'name' => 'Consultation', 'category_code' => 'OUTPATIENT'])->id;
    $this->lab = $net->addMedicalService(['code' => 'LAB_E4', 'name' => 'Lab test', 'category_code' => 'OUTPATIENT'])->id;
    $network = $net->createNetwork($this->tenant->id, ['code' => 'E4NET', 'name' => 'E4 network', 'network_type_code' => 'OPEN', 'category' => 'HEALTH'], null);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->provider->id, 'contract_number' => 'E4-001', 'effective_from' => '2026-01-01'], null)->id;
    $t = $net->draftTariff($this->tenant->id, $this->contract, '2026-01-01', 'XAF', [
        ['medical_service_id' => $this->cons, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80],
    ], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $t->id, (string) Str::uuid());
});

function hpcInvoice(array $over = []): array
{
    return array_merge([
        'provider_id' => test()->provider->id, 'contract_id' => test()->contract, 'invoice_reference' => 'INV-'.Str::random(6), 'service_date' => now()->toDateString(),
        'policy_id' => test()->policy->id, 'member_party_id' => test()->party->id,
        'lines' => [['medical_service_id' => test()->cons, 'quantity' => 2, 'unit_price_minor' => 18000], ['medical_service_id' => test()->lab, 'unit_price_minor' => 5000]],
    ], $over);
}

function hpcToReview(array $over = []): string
{
    Passport::actingAs(test()->clerk, [], 'api');
    $id = test()->postJson('/api/v1/health/provider-claims', hpcInvoice($over), test()->h)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');
    test()->postJson("/api/v1/health/provider-claims/{$id}/submit", [], test()->h)->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    Passport::actingAs(test()->adjuster, [], 'api');
    test()->postJson("/api/v1/health/provider-claims/{$id}/review", [], test()->h)->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');

    return $id;
}

it('REQ-HLT-003: invoice lines are priced against the contracted tariff with a per-line EOB and linked to a member Claim', function () {
    $id = hpcToReview();
    Passport::actingAs($this->clerk, [], 'api');
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", [], $this->h)->assertStatus(403); // maker-checker
    Passport::actingAs($this->adjuster, [], 'api');
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", ['note' => 'Standard tariff'], $this->h)->assertOk()->assertJsonPath('data.status', 'PARTIALLY_APPROVED');

    $eob = $this->getJson("/api/v1/health/provider-claims/{$id}/eob", $this->h)->assertOk()->json('data');
    expect($eob['lines'][0])->toMatchArray(['billed_minor' => 36000, 'allowed_minor' => 30000, 'copay_minor' => 6000, 'insurer_share_minor' => 19200,
        'member_share_minor' => 10800, 'rejected_minor' => 6000, 'decision' => 'PARTIALLY_APPROVED', 'reason_code' => 'ABOVE_TARIFF', 'tariff_version' => 1])
        ->and($eob['lines'][1])->toMatchArray(['billed_minor' => 5000, 'allowed_minor' => 0, 'rejected_minor' => 5000, 'decision' => 'REJECTED', 'reason_code' => 'NO_CONTRACTED_TARIFF'])
        ->and($eob['totals'])->toMatchArray(['billed_minor' => 41000, 'allowed_minor' => 30000, 'insurer_share_minor' => 19200, 'rejected_minor' => 11000]);

    $c = DB::table('health_provider_claims')->find($id);
    $claim = DB::table('claims')->find($c->claim_id);
    expect($claim->policy_id)->toBe($this->policy->id)->and($claim->claimant_party_id)->toBe($this->party->id)
        ->and((int) $claim->approved_amount_minor)->toBe(19200)
        ->and(json_decode($claim->loss_details, true)['provider_claim_id'])->toBe($id);
});

it('REQ-HLT-003: payable → PAYABLE CLAIM obligation to the provider party, settled in a batch with approved/paid postings and a statement', function () {
    $id = hpcToReview();
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", ['lines' => [['line_no' => 2, 'reject' => true, 'reason_code' => 'NOT_COVERED']]], $this->h)->assertOk();
    $this->postJson("/api/v1/health/provider-claims/{$id}/payable", [], $this->h)->assertOk()->assertJsonPath('data.status', 'PAYABLE');

    $c = DB::table('health_provider_claims')->find($id);
    $o = DB::table('financial_obligations')->find($c->financial_obligation_id);
    expect($o)->toMatchArray(['kind' => 'PAYABLE', 'type' => 'CLAIM', 'creditor_type' => 'party', 'creditor_id' => $this->provider->party_id, 'status' => 'OPEN'])
        ->and((int) $o->amount_minor)->toBe(19200)
        ->and(DB::table('journals')->where(['reference_type' => 'health.provider_claim.approved', 'reference_id' => $id, 'status' => 'POSTED'])->exists())->toBeTrue();

    // A second payable claim; the batch groups both.
    $id2 = hpcToReview(['lines' => [['medical_service_id' => $this->cons, 'unit_price_minor' => 15000]]]);
    $this->postJson("/api/v1/health/provider-claims/{$id2}/adjudicate", [], $this->h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->postJson("/api/v1/health/provider-claims/{$id2}/payable", [], $this->h)->assertOk();
    // 15000 − 3000 copay = 12000 × 80 % = 9600
    $batch = $this->postJson('/api/v1/health/provider-settlements', ['provider_id' => $this->provider->id, 'currency' => 'XAF'], $this->h)->assertCreated()
        ->assertJsonPath('data.total_minor', 28800)->assertJsonPath('data.claim_count', 2)->json('data.id');
    $this->postJson('/api/v1/health/provider-settlements', ['provider_id' => $this->provider->id, 'currency' => 'XAF'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/health/provider-settlements/{$batch}/pay", ['payment_reference' => 'VIR-001'], $this->h)->assertOk()->assertJsonPath('data.status', 'PAID');
    $this->postJson("/api/v1/health/provider-settlements/{$batch}/pay", ['payment_reference' => 'VIR-001'], $this->h)->assertStatus(409);

    expect(DB::table('health_provider_claims')->whereIn('id', [$id, $id2])->pluck('status')->unique()->all())->toBe(['PAID'])
        ->and(DB::table('financial_obligations')->where('id', $c->financial_obligation_id)->value('status'))->toBe('SETTLED')
        ->and(DB::table('journals')->where('reference_type', 'health.provider_claim.paid')->count())->toBe(2)
        ->and(DB::table('outbox_messages')->where('event_name', 'health.provider_claim.paid')->count())->toBe(2);

    $st = $this->getJson("/api/v1/health/providers/{$this->provider->id}/statement?from=".now()->subMonth()->toDateString().'&to='.now()->toDateString(), $this->h)->assertOk()->json('data');
    expect($st['totals'])->toMatchArray(['count' => 2, 'billed_minor' => 56000, 'insurer_share_minor' => 28800, 'paid_minor' => 28800, 'outstanding_minor' => 0])
        ->and($st['batches'])->toHaveCount(1);
});

it('REQ-HLT-003: disputes open a PROVIDER_DISPUTE case; reopen re-adjudicates, uphold restores; illegal moves are refused', function () {
    $id = hpcToReview();
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", ['lines' => [['line_no' => 1, 'reject' => true]]], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", ['lines' => [['line_no' => 1, 'reject' => true, 'reason_code' => 'DOCUMENTATION']]], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'REJECTED');
    $this->postJson("/api/v1/health/provider-claims/{$id}/payable", [], $this->h)->assertStatus(409);

    $this->postJson("/api/v1/health/provider-claims/{$id}/dispute", ['reason' => 'Medical report attached'], $this->h)->assertOk()->assertJsonPath('data.status', 'DISPUTED');
    $c = DB::table('health_provider_claims')->find($id);
    expect(DB::table('cases')->where('id', $c->dispute_case_id)->value('case_type_code'))->toBe('PROVIDER_DISPUTE');

    $this->postJson("/api/v1/health/provider-claims/{$id}/dispute/resolve", ['outcome' => 'REOPEN', 'reason' => 'Report accepted'], $this->h)->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
    $this->postJson("/api/v1/health/provider-claims/{$id}/adjudicate", [], $this->h)->assertOk()->assertJsonPath('data.status', 'PARTIALLY_APPROVED');
    $this->postJson("/api/v1/health/provider-claims/{$id}/dispute", ['reason' => 'Lab test is contracted'], $this->h)->assertOk();
    $this->postJson("/api/v1/health/provider-claims/{$id}/dispute/resolve", ['outcome' => 'UPHOLD', 'reason' => 'Lab not in tariff'], $this->h)->assertOk()->assertJsonPath('data.status', 'PARTIALLY_APPROVED');

    expect(DB::table('cases')->where(['subject_type' => 'health_provider_claim', 'subject_id' => $id])->count())->toBe(2);
    $history = $this->getJson("/api/v1/health/provider-claims/{$id}", $this->h)->json('data.history');
    expect(array_column($history, 'to_status'))->toBe(['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REJECTED', 'DISPUTED', 'UNDER_REVIEW', 'PARTIALLY_APPROVED', 'DISPUTED', 'PARTIALLY_APPROVED']);
    expect(fn () => DB::transaction(fn () => DB::table('health_provider_claim_events')->where('health_provider_claim_id', $id)->delete()))->toThrow(QueryException::class);
});

it('REQ-HLT-003: duplicate invoices, foreign contracts, ineligible members and unknown preauth are handled', function () {
    Passport::actingAs($this->clerk, [], 'api');
    $inv = hpcInvoice(['invoice_reference' => 'INV-DUP']);
    $this->postJson('/api/v1/health/provider-claims', $inv, $this->h)->assertCreated();
    $this->postJson('/api/v1/health/provider-claims', $inv, $this->h)->assertStatus(409);
    $other = app(ProviderRegistry::class)->register(['category' => 'HEALTH', 'name' => 'Other clinic', 'provider_type_code' => 'CLINIC'], null);
    $this->postJson('/api/v1/health/provider-claims', hpcInvoice(['provider_id' => $other->id]), $this->h)->assertStatus(422);

    // service date outside the policy cover → NOT_ELIGIBLE (fallback when E2's EligibilityService is absent)
    $id = hpcToReview(['service_date' => now()->addYears(2)->toDateString(), 'preauth_id' => (string) Str::uuid()]);
    $eob = app(ProviderClaimService::class)->eob($this->tenant->id, $id);
    if (! class_exists('App\\Application\\Health\\Eligibility\\EligibilityService')) {
        expect($eob['lines'][0]['reason_code'])->toBe('NOT_ELIGIBLE');
    }
    expect(DB::table('health_provider_claims')->where('id', $id)->value('preauth_verified'))->toBeFalse();

    // other tenants cannot see it
    $t2 = makeAuthTestTenant('hpc2');
    Passport::actingAs(makeAuthTestUser($t2, HPC_PERMS, 'HPC_T2'), [], 'api');
    $this->getJson("/api/v1/health/provider-claims/{$id}", ['X-Tenant-ID' => $t2->id])->assertStatus(404);
    Passport::actingAs(makeAuthTestUser($this->tenant, ['health.provider_claims.view'], 'HPC_RO'), [], 'api');
    $this->postJson('/api/v1/health/provider-claims', hpcInvoice(), $this->h)->assertStatus(403);
});

it('REQ-HLT-003: accounting events have default mappings and outbox events are catalogued', function () {
    foreach (['health.provider_claim.approved', 'health.provider_claim.paid'] as $e) {
        expect(DefaultChartOfAccounts::EVENTS)->toHaveKey($e)
            ->and(DB::table('accounting_event_mappings')->where(['event_code' => $e, 'status' => 'ACTIVE'])->whereNull('tenant_id')->exists())->toBeTrue();
    }
    foreach (['health.provider_claim.submitted', 'health.provider_claim.adjudicated', 'health.provider_claim.payable', 'health.provider_claim.disputed',
        'health.provider_claim.dispute_resolved', 'health.provider_claim.paid', 'health.provider_settlement.created', 'health.provider_settlement.paid'] as $e) {
        expect(DomainEventCatalogue::has($e))->toBeTrue();
    }
});
