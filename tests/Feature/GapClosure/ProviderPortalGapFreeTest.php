<?php

declare(strict_types=1);

/**
 * Agent GP4 — gap closure pack 04 (health provider / service / tariff master) + Provider Portal Hospital/Clinic
 * Gap-Free spec v1. REQ-PRV-001, REQ-PRV-002, REQ-PRV-003, REQ-HLT-001, REQ-HLT-002, REQ-HLT-003.
 */

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Application\Import\ImportTargetRegistry;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Application\Providers\Workspace\ProviderWorkspaceRegister;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const GP4_ALL = ['provider.dashboard.view', 'provider.patient.search', 'provider.eligibility.check', 'provider.benefits.view', 'provider.preauth.create', 'provider.preauth.view',
    'provider.preauth.respond_to_query', 'provider.admission.create', 'provider.admission.extend', 'provider.treatment.view', 'provider.treatment.update', 'provider.claim.create',
    'provider.claim.submit', 'provider.claim.view', 'provider.claim.respond_to_query', 'provider.tariff.view', 'provider.contract.view', 'provider.finance.view',
    'provider.settlement.view', 'provider.reconciliation.view', 'provider.reconciliation.match', 'provider.dispute.create', 'provider.dispute.view', 'provider.documents.view',
    'provider.reports.view', 'provider.reports.export', 'provider.users.manage', 'provider.settings.manage', 'provider.audit.view', 'provider_portal.profile.view', 'provider_portal.network.view'];

function gp4Employee($tenant, object $provider, array $perms = GP4_ALL): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $provider->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($tenant, $perms, 'PROVIDER_ADMIN');
    $u->update(['party_id' => $person->id]);

    return $u->refresh();
}

function gp4Key(): array
{
    return test()->h + ['Idempotency-Key' => (string) Str::uuid()];
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01', 'coverage_ends_at' => '2026-12-31',
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $version = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $version, 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => '2026-01-01', 'recorded_at' => now()->subDay(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('policy_coverages')->insert(['id' => (string) Str::uuid(), 'policy_id' => $this->policy->id, 'policy_version_id' => $version, 'coverage_code' => 'OUTPATIENT',
        'limit_minor' => 5_000_000, 'deductible_minor' => 0, 'currency' => 'XAF', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_GP4', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    $this->xray = $net->addMedicalService(['code' => 'XRAY_GP4', 'name' => 'X-ray', 'category_code' => 'OUTPATIENT']);
    DB::table('health_benefit_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT',
        'benefit_code' => 'OP', 'created_at' => now(), 'updated_at' => now()]);

    $reg = app(ProviderRegistry::class);
    $this->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique GP4 '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    $this->outsider = $reg->register(['category' => 'HEALTH', 'name' => 'Hors réseau '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach ([$this->clinic, $this->outsider] as $p) {
        foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
            $reg->transition($p->id, $to, null, null, null);
        }
    }
    $this->main = $reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $this->annex = $reg->addFacility($this->clinic->id, ['code' => 'ANNEX', 'name' => 'Annex']);
    $network = $net->createNetwork($this->tenant->id, ['code' => 'GP4NET', 'name' => 'GP4 network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH'], null);
    $net->addMember($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'effective_from' => '2026-01-01'], null);
    DB::table('health_policy_networks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'provider_network_id' => $network->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'contract_number' => 'GP4-001', 'effective_from' => '2026-01-01'], null);
    $t = $net->draftTariff($this->tenant->id, $this->contract->id, '2026-01-01', 'XAF',
        [['medical_service_id' => $this->cons->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $t->id, (string) Str::uuid());
    $this->tariff = $t;

    $this->user = gp4Employee($this->tenant, $this->clinic);
    Passport::actingAs($this->user, [], 'api');
});

it('REQ-HLT-001 REQ-PRV-003: reception verifies a member (coverage + network status, no medical history); verification is retrievable and audited', function () {
    $r = $this->postJson('/api/v1/provider-portal/eligibility/check', ['member_ref' => $this->f['party']->id, 'policy_id' => $this->policy->id, 'service_code' => 'CONS_GP4',
        'search_method' => 'POLICY_NUMBER', 'service_date' => '2026-03-10'], gp4Key())->assertOk()->json('data');
    expect($r)->toMatchArray(['coverage_status' => 'ELIGIBLE', 'eligible' => true, 'provider_network_status' => 'IN_NETWORK', 'insurer_id' => $this->tenant->id])
        ->and($r)->not->toHaveKeys(['diagnosis', 'history', 'claims_history']);
    $this->getJson('/api/v1/provider-portal/eligibility/'.$r['verification_reference'], $this->h)->assertOk()->assertJsonPath('data.coverage_status', 'ELIGIBLE');
    expect(DB::table('outbox_messages')->where('event_name', 'provider_portal.eligibility.checked')->count())->toBe(1);

    // Duplicate API submission is replayed, not re-executed (idempotency); a mutation without a key is refused.
    $h = gp4Key();
    $body = ['member_ref' => $this->f['party']->id, 'policy_id' => $this->policy->id, 'service_code' => 'CONS_GP4', 'service_date' => '2026-03-10'];
    $a = $this->postJson('/api/v1/provider-portal/eligibility/check', $body, $h)->assertOk()->json('data.verification_reference');
    $this->postJson('/api/v1/provider-portal/eligibility/check', $body, $h)->assertOk()->assertHeader('X-Idempotent-Replay')->assertJsonPath('data.verification_reference', $a);
    $this->postJson('/api/v1/provider-portal/eligibility/check', $body, $this->h)->assertStatus(422);
});

it('REQ-HLT-001: inactive policy and out-of-network provider never produce an approved result; insurer outage is not a false approval', function () {
    $body = ['member_ref' => $this->f['party']->id, 'policy_id' => $this->policy->id, 'service_code' => 'CONS_GP4', 'service_date' => '2026-03-10'];
    DB::table('policies')->where('id', $this->policy->id)->update(['status' => 'LAPSED']);
    $r = $this->postJson('/api/v1/provider-portal/eligibility/check', $body, gp4Key())->assertOk()->json('data');
    expect($r['eligible'])->toBeFalse()->and($r['failure_states'])->toContain('POLICY_INACTIVE');
    DB::table('policies')->where('id', $this->policy->id)->update(['status' => 'ACTIVE']);

    Passport::actingAs(gp4Employee($this->tenant, $this->outsider), [], 'api');
    $r = $this->postJson('/api/v1/provider-portal/eligibility/check', $body, gp4Key())->assertOk()->json('data');
    expect($r['eligible'])->toBeFalse()->and($r['provider_network_status'])->toBe('OUT_OF_NETWORK')->and($r['failure_states'])->toContain('PROVIDER_OUT_OF_NETWORK');

    // Simulated engine outage (storage failure inside the eligibility engine).
    DB::statement('ALTER TABLE health_eligibility_checks RENAME TO health_eligibility_checks_offline');
    $r = $this->postJson('/api/v1/provider-portal/eligibility/check', $body, gp4Key())->assertOk()->json('data');
    expect($r)->toMatchArray(['eligible' => false, 'coverage_status' => 'UNKNOWN', 'failure_states' => ['INSURER_SYSTEM_UNAVAILABLE'], 'ui_state' => 'INSURER_UNAVAILABLE']);
});

it('REQ-PRV-003: facility users see only assigned facilities; head office sees all; clinical fields only for clinical roles', function () {
    $ep = $this->postJson('/api/v1/provider-portal/treatment-episodes', ['facility_id' => $this->annex->id, 'policy_id' => $this->policy->id, 'member_ref' => $this->f['party']->id,
        'episode_type' => 'OUTPATIENT', 'started_on' => '2026-03-10', 'diagnosis_summary' => 'J06.9 URTI'], gp4Key())->assertCreated()->json('data');
    expect($ep['diagnosis_summary'])->toBeNull(); // legacy organisation employee: no clinical role

    $desk = gp4Employee($this->tenant, $this->clinic);
    $doctor = gp4Employee($this->tenant, $this->clinic);
    $this->postJson('/api/v1/provider-portal/users', ['user_id' => $desk->id, 'provider_role' => 'RECEPTION_ELIGIBILITY_OFFICER', 'facility_scope' => 'ASSIGNED', 'facility_ids' => [$this->main->id]], gp4Key())
        ->assertCreated()->assertJsonPath('data.provider_role', 'FRONT_DESK');
    $this->postJson('/api/v1/provider-portal/users', ['user_id' => $doctor->id, 'provider_role' => 'DOCTOR', 'facility_scope' => 'ALL'], gp4Key())->assertCreated();
    $this->postJson('/api/v1/provider-portal/users', ['user_id' => $doctor->id, 'provider_role' => 'WIZARD', 'facility_scope' => 'ALL'], gp4Key())->assertStatus(422);

    Passport::actingAs($desk, [], 'api');
    $this->getJson('/api/v1/provider-portal/treatment-episodes/'.$ep['id'], $this->h)->assertNotFound();
    expect($this->getJson('/api/v1/provider-portal/treatment-episodes', $this->h)->assertOk()->json('data'))->toBe([]);
    $this->postJson('/api/v1/provider-portal/treatment-episodes', ['facility_id' => $this->annex->id, 'member_ref' => 'X', 'episode_type' => 'OUTPATIENT'], gp4Key())->assertNotFound();

    Passport::actingAs($doctor, [], 'api');
    $this->getJson('/api/v1/provider-portal/treatment-episodes/'.$ep['id'], $this->h)->assertOk()->assertJsonPath('data.diagnosis_summary', 'J06.9 URTI');
    $this->getJson('/api/v1/provider-portal/documents', $this->h)->assertOk()->assertJsonMissing(['ui_state' => 'PERMISSION_DENIED']);
    Passport::actingAs($desk, [], 'api');
    $this->getJson('/api/v1/provider-portal/documents', $this->h)->assertOk()->assertJsonPath('meta.ui_state', 'PERMISSION_DENIED');
});

it('REQ-HLT-003 REQ-PRV-002: a treatment episode generates the provider claim (no re-entry) with the tariff version in force; a missing tariff routes to review', function () {
    $ep = $this->postJson('/api/v1/provider-portal/treatment-episodes', ['facility_id' => $this->main->id, 'policy_id' => $this->policy->id, 'member_ref' => $this->f['party']->id,
        'episode_type' => 'OUTPATIENT', 'started_on' => '2026-03-10'], gp4Key())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/bill", ['invoice_reference' => 'INV-0'], gp4Key())->assertStatus(409); // still OPEN
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/close", [], gp4Key())->assertStatus(422); // no services yet
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/lines", ['service_code' => 'CONS_GP4', 'quantity' => 2, 'unit_price_minor' => 18000], gp4Key())->assertCreated();
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/lines", ['service_code' => 'XRAY_GP4', 'unit_price_minor' => 5000], gp4Key())->assertCreated();
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/close", ['ended_on' => '2026-03-10'], gp4Key())->assertOk()->assertJsonPath('data.status', 'CLOSED');
    $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/bill", ['invoice_reference' => 'INV-1', 'contract_id' => (string) Str::uuid()], gp4Key())
        ->assertStatus(409)->assertJsonPath('code', 'CONTRACT_REQUIRED');
    $bill = $this->postJson("/api/v1/provider-portal/treatment-episodes/{$ep}/bill", ['invoice_reference' => 'INV-1'], gp4Key())->assertCreated()->json('data');

    expect($bill['claim'])->toMatchArray(['status' => 'DRAFT', 'billed_minor' => 41000, 'provider_facility_id' => $this->main->id, 'source_system' => 'TREATMENT_EPISODE', 'policy_id' => $this->policy->id])
        ->and($bill['claim']['lines'])->toHaveCount(2)
        ->and($bill['tariff_resolution'])->toBe([
            ['line_no' => 1, 'service_date' => '2026-03-10', 'tariff_status' => 'TARIFF_FOUND', 'tariff_version' => 1, 'contracted_price_minor' => 15000],
            ['line_no' => 2, 'service_date' => '2026-03-10', 'tariff_status' => 'NO_TARIFF_REVIEW_REQUIRED', 'tariff_version' => null, 'contracted_price_minor' => null],
        ]);
    $this->getJson("/api/v1/provider-portal/treatment-episodes/{$ep}", $this->h)->assertJsonPath('data.status', 'BILLED')->assertJsonPath('data.health_provider_claim_id', $bill['claim']['id']);
    $this->postJson('/api/v1/provider-portal/claims/'.$bill['claim']['id'].'/submit', [], gp4Key())->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    $this->postJson('/api/v1/provider-portal/claims/'.$bill['claim']['id'].'/respond-to-query', ['response' => 'Report attached'], gp4Key())->assertOk();
    expect(collect($this->getJson('/api/v1/provider-portal/claims/'.$bill['claim']['id'], $this->h)->json('data.history'))->pluck('event'))->toContain('PROVIDER_RESPONSE');
});

it('REQ-HLT-003 REQ-PRV-003: per-insurer derived accounts, bulk payment allocation, unmatched payments, reason-coded disputes that never disappear, exports = filters', function () {
    $claims = app(ProviderClaimService::class);
    $adj = makeAuthTestUser($this->tenant, []);
    $ids = [];
    foreach (['INV-A', 'INV-B'] as $inv) {
        $id = $this->postJson('/api/v1/provider-portal/claims', ['contract_id' => $this->contract->id, 'invoice_reference' => $inv, 'service_date' => '2026-03-10', 'policy_id' => $this->policy->id,
            'member_party_id' => $this->f['party']->id, 'facility_id' => $this->main->id, 'lines' => [['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000], ['medical_service_id' => $this->xray->id, 'unit_price_minor' => 5000]]],
            gp4Key())->assertCreated()->assertJsonPath('data.provider_facility_id', $this->main->id)->json('data.id');
        $this->postJson("/api/v1/provider-portal/claims/{$id}/submit", [], gp4Key())->assertOk();
        $claims->startReview($this->tenant->id, $id, $adj->id);
        $claims->adjudicate($this->tenant->id, $id, [], null, $adj->id); // XRAY: no tariff → rejected with a reason code
        $ids[] = $id;
    }
    $claims->markPayable($this->tenant->id, $ids[0], $adj->id);
    $claims->markPayable($this->tenant->id, $ids[1], $adj->id);

    // (15000 − 3000) × 80 % = 9600 per claim; nothing paid yet.
    $acc = $this->getJson('/api/v1/provider-portal/accounts', $this->h)->assertOk()->json('data');
    expect($acc)->toHaveCount(1)->and($acc[0])->toMatchArray(['insurer_id' => $this->tenant->id, 'submitted_amount' => 40000, 'approved_amount' => 19200, 'payable_amount' => 19200,
        'outstanding_amount' => 19200, 'rejected_amount' => 10000, 'paid_amount' => 0, 'balance_source' => 'DERIVED_FROM_TRANSACTIONS', 'editable' => false]);
    $detail = $this->getJson('/api/v1/provider-portal/accounts/'.$this->tenant->id, $this->h)->assertOk()->json('data');
    $deductions = collect($detail['entries'])->where('type', 'DEDUCTIONS');
    expect($deductions)->toHaveCount(2)->and($deductions->pluck('reason_code')->unique()->all())->toBe(['CONTRACT_TARIFF_ADJUSTMENT']);

    $batch = app(ProviderSettlementService::class)->createBatch($this->tenant->id, $this->clinic->id, 'XAF', null, $adj->id);
    app(ProviderSettlementService::class)->payBatch($this->tenant->id, $batch->id, 'VIR-9', $adj->id);
    $this->getJson('/api/v1/provider-portal/settlements/'.$batch->id, $this->h)->assertOk()->assertJsonPath('data.statement.approved_claims', 19200);

    // Bulk payment with the settlement reference: auto-allocated across both claims.
    $rec = $this->postJson('/api/v1/provider-portal/reconciliations', ['payment_reference' => 'VIR-9', 'received_on' => '2026-04-01', 'currency' => 'XAF', 'amount_minor' => 19200,
        'settlement_batch_id' => $batch->id], gp4Key())->assertCreated()->json('data');
    expect($rec)->toMatchArray(['status' => 'MATCHED', 'allocated_minor' => 19200, 'unallocated_minor' => 0])->and($rec['lines'])->toHaveCount(2);
    // An unmatched payment stays visible; manual matching needs a reason and never over-allocates.
    $u = $this->postJson('/api/v1/provider-portal/reconciliations', ['payment_reference' => 'VIR-X', 'received_on' => '2026-04-02', 'currency' => 'XAF', 'amount_minor' => 5000], gp4Key())
        ->assertCreated()->assertJsonPath('data.status', 'UNMATCHED_EXTERNAL')->json('data.id');
    $this->postJson("/api/v1/provider-portal/reconciliations/{$u}/match", ['allocations' => [['claim_id' => $ids[0], 'amount_minor' => 100]]], gp4Key())->assertStatus(422);
    $this->postJson("/api/v1/provider-portal/reconciliations/{$u}/match", ['allocations' => [['claim_id' => $ids[0], 'amount_minor' => 100]], 'reason' => 'Overpayment'], gp4Key())
        ->assertStatus(422)->assertJsonPath('code', 'ALLOCATION_EXCEEDS_CLAIM');
    $unrec = $this->getJson('/api/v1/provider-portal/reports/unreconciled_payments', $this->h)->assertOk()->json('data');
    expect(array_column($unrec, 'payment_reference'))->toBe(['VIR-X']);
    expect(fn () => DB::transaction(fn () => DB::table('provider_reconciliation_lines')->delete()))->toThrow(QueryException::class);

    // Reason-coded dispute on a deducted line; it never disappears from financial history.
    $this->postJson('/api/v1/provider-portal/disputes', ['subject_type' => 'CLAIM_LINE', 'claim_id' => $ids[0], 'claim_line_no' => 2, 'description' => 'X-ray is contracted'], gp4Key())->assertStatus(422);
    $d = $this->postJson('/api/v1/provider-portal/disputes', ['subject_type' => 'CLAIM_LINE', 'claim_id' => $ids[0], 'claim_line_no' => 2, 'reason_code' => 'TARIFF_DIFFERENCE',
        'disputed_amount_minor' => 5000, 'description' => 'X-ray is contracted'], gp4Key())->assertCreated()->json('data');
    expect($d['status'])->toBe('SUBMITTED');
    expect(fn () => DB::transaction(fn () => DB::table('provider_disputes')->where('id', $d['id'])->delete()))->toThrow(QueryException::class);
    expect(collect($this->getJson('/api/v1/provider-portal/accounts/'.$this->tenant->id, $this->h)->json('data.entries'))->where('type', 'DISPUTED_AMOUNT')->count())->toBe(1);

    // Exports match the active filters exactly.
    $json = $this->getJson('/api/v1/provider-portal/reports/claims_by_status?facility_id='.$this->main->id.'&claim_status=PAID', $this->h)->assertOk()->json('data');
    expect($json)->toBe([['status' => 'PAID', 'claims' => 2, 'billed_minor' => 40000, 'insurer_share_minor' => 19200, 'rejected_minor' => 10000]]);
    $csv = $this->get('/api/v1/provider-portal/reports/claims_by_status?format=csv&facility_id='.$this->main->id.'&claim_status=PAID', $this->h)->assertOk()->getContent();
    expect(array_values(array_filter(explode("\n", trim($csv)))))->toHaveCount(1 + count($json));
    $this->getJson('/api/v1/provider-portal/reports/claims_by_status?facility_id='.$this->annex->id, $this->h)->assertOk()->assertJsonCount(0, 'data');

    // Audit trail covers claims, settlements, reconciliation and disputes.
    $actions = array_column($this->getJson('/api/v1/provider-portal/audit', $this->h)->assertOk()->json('data'), 'action');
    expect($actions)->toContain('health.provider_claim.submitted', 'provider_portal.reconciliation.matched', 'provider_portal.dispute.opened');
});

it('REQ-PRV-003: every spec screen, entity, report and filter is registered with EN/FR labels; the provider panel serves the screens', function () {
    $spec = json_decode(file_get_contents(database_path('data/gap_closure_2026/OpesInsure_Provider_Portal_Hospital_Clinic_Gap_Free_Implementation_Spec_v1.json')), true);
    expect(array_keys(ProviderWorkspaceRegister::SCREENS))->toBe($spec['ui_screen_register'])
        ->and(array_keys(ProviderWorkspaceRegister::ENTITY_MAP))->toBe($spec['database_entities'])
        ->and(ProviderWorkspaceRegister::REPORTS)->toBe($spec['reports'])
        ->and(ProviderWorkspaceRegister::FILTERS)->toBe($spec['filters'])
        ->and(array_keys(ProviderWorkspaceRegister::EVENTS))->toBe($spec['events'])
        ->and(ProviderWorkspaceRegister::UI_STATES)->toBe($spec['states_and_failure_handling']['ui_states_required']);
    foreach ($spec['rbac_permissions'] as $perm) {
        expect(collect(config('permissions'))->except(['business_data', 'never_grant_to'])->flatMap(fn ($s) => array_keys($s))->contains($perm))->toBeTrue($perm);
    }
    foreach (ProviderWorkspaceRegister::screens() as $s) {
        expect($s['label']['en'])->not->toStartWith('provider_workspace.')->and($s['label']['fr'])->not->toStartWith('provider_workspace.');
    }
    foreach ($spec['reports'] as $rep) {
        $this->getJson('/api/v1/provider-portal/reports/'.strtolower($rep), $this->h)->assertOk();
    }
    $this->getJson('/api/v1/provider-portal/screens', $this->h)->assertOk()->assertJsonCount(45, 'data.screens');
    $this->getJson('/api/v1/provider-portal/dashboard', $this->h)->assertOk()->assertJsonStructure(['data' => ['provider_executive', 'insurance_desk', 'claims_billing', 'finance']]);

    $this->actingAs($this->user, 'web');
    foreach (\App\Application\Providers\Workspace\Filament\ProviderPanelProvider::pages() as $page) {
        $res = $this->get('/provider/'.$page::getSlug());
        expect($res->status())->toBe(200, $page.' → '.$res->headers->get('Location'));
    }
    $this->actingAs(makeAuthTestUser($this->tenant, GP4_ALL), 'web'); // not a provider user
    $this->get('/provider/dashboard')->assertForbidden();
});

it('REQ-PRV-001 REQ-PRV-002: pack 04 vocabularies seed as master data (synonyms as aliases), official master / tariffs are readiness gates with import targets', function () {
    app(MasterDataSeeder::class)->run();
    $codes = fn (string $list) => DB::table('master_data_values')->where(['domain_code' => 'provider', 'list_code' => $list])->pluck('code')->all();
    expect($codes('service_family'))->toHaveCount(24)
        ->and($codes('provider_type'))->toContain('DISTRICT_HOSPITAL', 'CLINIC')->not->toContain('PRIVATE_CLINIC', 'CSI')
        ->and($codes('medical_specialty'))->toContain('NEONATOLOGY', 'PAEDIATRICS')->not->toContain('PEDIATRICS');
    expect(DB::table('master_data_aliases')->where('alias', 'CSI')->exists())->toBeTrue();

    $rows = collect(app(DataReadinessRegistry::class)->domain('health'))->keyBy('item');
    expect($rows['gap04.provider_master']['status'])->toBe('PENDING_SOURCE')->and($rows['gap04.provider_master']['production_usable'])->toBeFalse()
        ->and($rows['gap04.provider_tariffs']['status'])->toBe('VERIFIED') // an insurer-approved tariff exists in this fixture
        ->and($rows['gap04.service_families']['status'])->toBe('PLATFORM_NORMALIZED');

    $targets = app(ImportTargetRegistry::class)->options();
    expect($targets)->toHaveKeys(['health_provider_master', 'medical_services']);
    $seen = [];
    $t = app(ImportTargetRegistry::class)->get('health_provider_master');
    expect($t->check(['official_name' => 'Hôpital de District de Bonassama', 'provider_type' => 'DISTRICT_HOSPITAL', 'city' => 'Douala'], [], $seen)['status'])->toBe('ERROR') // no source_url
        ->and($t->check(['official_name' => 'Hôpital de District de Bonassama', 'provider_type' => 'DISTRICT_HOSPITAL', 'city' => 'Douala', 'source_url' => 'https://minsante.cm/x'], [], $seen)['status'])->toBe('NEW');
    $id = $t->import(['official_name' => 'Hôpital de District de Bonassama', 'provider_type' => 'DISTRICT_HOSPITAL', 'city' => 'Douala', 'source_url' => 'https://minsante.cm/x'], [], null, (string) Str::uuid());
    $p = DB::table('provider_profiles')->find($id);
    expect($p->credentialing_status)->toBe('PROSPECT')->and($p->data_status)->toBe('PENDING_VERIFICATION');
    $seen = [];
    expect($t->check(['official_name' => 'Hôpital de District de Bonassama', 'provider_type' => 'DISTRICT_HOSPITAL', 'city' => 'Douala', 'source_url' => 'https://minsante.cm/x'], [], $seen)['status'])->toBe('DUPLICATE');
    expect(collect(app(DataReadinessRegistry::class)->domain('health'))->firstWhere('item', 'gap04.provider_master')['status'])->toBe('UNVERIFIED');
});

// ----------------------------------------------------------------- web screens (provider panel forms reuse the API actions)

function gp4Web(object $t): void
{
    test()->actingAs($t->user, 'web');
    app(\App\Domain\Tenancy\TenantContext::class)->set($t->tenant->id);
    app()->instance(\App\Application\Providers\Portal\ProviderScope::class.'@panel',
        new \App\Application\Providers\Portal\ProviderScope($t->clinic->id, [$t->clinic->id], $t->clinic->party_id, null));
}

it('REQ-PRV-003 web: preauthorization request form posts through the API action; claim capture → submit → EOB detail; admissions screen', function () {
    gp4Web($this);
    $pa = \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage::class)
        ->set('request_type', 'OUTPATIENT')->set('policy_id', $this->policy->id)->set('member_ref', $this->f['party']->id)->set('facility_id', $this->main->id)
        ->set('service_code', 'CONS_GP4')->set('quantity', '1')->set('details', ['consultation_date' => '2026-03-10', 'diagnosis_code' => 'J06'])
        ->call('submitRequest')->assertSet('state', 'SUCCESS')->assertSee('CONS_GP4');
    $id = $pa->get('selected');
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('provider_profile_id'))->toBe($this->clinic->id);
    // A missing required type field is a field error, nothing is created.
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage::class)
        ->set('request_type', 'OUTPATIENT')->set('policy_id', $this->policy->id)->set('service_code', 'CONS_GP4')->call('submitRequest')
        ->assertSet('state', 'VALIDATION_FAILED')->assertHasErrors('details.consultation_date');
    expect(DB::table('health_preauthorizations')->count())->toBe(1);

    $c = \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ClaimsPage::class)
        ->set('contract_id', $this->contract->id)->set('invoice_reference', 'WEB-INV-1')->set('service_date', '2026-03-10')->set('policy_id', $this->policy->id)
        ->set('facility_id', $this->main->id)->set('lines', [['medical_service_id' => $this->cons->id, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => '15000']])
        ->call('createClaim')->assertSet('state', 'SUCCESS');
    $claim = $c->get('selected');
    expect(DB::table('health_provider_claims')->where('id', $claim)->value('status'))->toBe('DRAFT');
    $c->call('submitClaim')->assertSet('state', 'SUCCESS')->assertSee('WEB-INV-1');
    expect(DB::table('health_provider_claims')->where('id', $claim)->value('status'))->toBe('SUBMITTED');

    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\AdmissionsPage::class)->assertOk();
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ContractsPage::class)->call('open', $this->contract->id)->assertSee('CONS_GP4');
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\DocumentsPage::class)->assertOk();
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ReportsPage::class)->set('report', 'CLAIMS_BY_STATUS')->assertSee('SUBMITTED')
        ->call('exportCsv')->assertFileDownloaded('claims_by_status.csv');
});

it('REQ-PRV-003 web: another provider\'s claim or contract never opens on this provider\'s screens', function () {
    $otherClaim = app(ProviderClaimService::class)->create($this->tenant->id, ['provider_id' => $this->clinic->id, 'contract_id' => $this->contract->id, 'invoice_reference' => 'MINE-1',
        'service_date' => '2026-03-10', 'policy_id' => $this->policy->id, 'lines' => [['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000]]], null);
    // Acting for the outsider provider: the clinic's claim and contract are invisible.
    $this->clinic = $this->outsider;
    $this->user = gp4Employee($this->tenant, $this->outsider);
    gp4Web($this);
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ClaimsPage::class)->assertDontSee('MINE-1')
        ->call('open', $otherClaim->id)->assertSee('Provider claim not found.');
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ContractsPage::class)->assertDontSee('GP4-001')
        ->call('open', $this->contract->id)->assertSee('Contract not found.');
});

// ----------------------------------------------------------------- round 2: episode / reconciliation / dispute forms, web idempotency, insurer health screens

it('REQ-PRV-003 web: treatment episode form opens, records services, closes and bills through the API actions', function () {
    gp4Web($this);
    $p = \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\TreatmentEpisodesPage::class)
        ->set('episode_type', 'OUTPATIENT')->set('policy_id', $this->policy->id)->set('member_ref', $this->f['party']->id)->set('facility_id', $this->main->id)->set('started_on', '2026-03-10')
        ->call('openEpisode')->assertSet('state', 'SUCCESS');
    $ep = $p->get('selected');
    expect(DB::table('treatment_episodes')->where('id', $ep)->value('status'))->toBe('OPEN');
    $p->set('service_code', 'CONS_GP4')->set('unit_price_minor', '18000')->call('addLine')->assertSet('state', 'SUCCESS')->assertSee('CONS_GP4')
        ->set('ended_on', '2026-03-10')->call('closeEpisode')->assertSet('state', 'SUCCESS')
        ->set('invoice_reference', 'EP-INV-1')->call('billEpisode')->assertSet('state', 'SUCCESS');
    expect(DB::table('treatment_episodes')->where('id', $ep)->value('status'))->toBe('BILLED')
        ->and(DB::table('health_provider_claims')->where('invoice_reference', 'EP-INV-1')->count())->toBe(1);
    // Validation lands on the field; nothing is created.
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\TreatmentEpisodesPage::class)->call('openEpisode')
        ->assertSet('state', 'VALIDATION_FAILED')->assertHasErrors('member_ref');
    expect(DB::table('treatment_episodes')->count())->toBe(1);
});

it('REQ-PRV-003 web: a double-submitted form (same submission key, same payload) is replayed, never executed twice; an edited form is a new submission', function () {
    gp4Web($this);
    $fill = fn ($c) => $c->set('payment_reference', 'VIR-DBL')->set('received_on', '2026-04-02')->set('currency', 'XAF')->set('amount_minor', '5000');
    $p = $fill(\Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\ReconciliationsPage::class));
    $token = $p->get('formToken');
    expect($token)->not->toBeNull(); // editing a field issued a submission key
    $p->call('recordPayment')->assertSet('state', 'SUCCESS');
    // Double click: the second request carries the same key and the same payload, so IdempotencyGuard replays it.
    $fill($p)->set('formToken', $token)->call('recordPayment')->assertSet('state', 'SUCCESS');
    expect(DB::table('provider_reconciliations')->where('payment_reference', 'VIR-DBL')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $token)->where('operation', 'provider_portal.web.reconciliationStore')->count())->toBe(1);
    // The user edits the form again: a fresh key, a real second submission.
    $fill($p)->set('payment_reference', 'VIR-DBL-2')->call('recordPayment')->assertSet('state', 'SUCCESS');
    expect(DB::table('provider_reconciliations')->where('payment_reference', 'like', 'VIR-DBL%')->count())->toBe(2);
    // Manual allocation needs a reason (field error, nothing allocated).
    $p->set('claim_id', (string) Str::uuid())->set('allocation_minor', '100')->call('allocate')->assertSet('state', 'VALIDATION_FAILED')->assertHasErrors('reason');
});

it('REQ-PRV-003 web: dispute form opens a reason-coded dispute on a claim line through the API action', function () {
    $claim = app(ProviderClaimService::class)->create($this->tenant->id, ['provider_id' => $this->clinic->id, 'contract_id' => $this->contract->id, 'invoice_reference' => 'DSP-1',
        'service_date' => '2026-03-10', 'policy_id' => $this->policy->id, 'lines' => [['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000]]], $this->user->id);
    DB::table('health_provider_claims')->where('id', $claim->id)->update(['provider_facility_id' => $this->main->id]);
    gp4Web($this);
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\DisputesPage::class)
        ->set('subject_type', 'CLAIM_LINE')->set('claim_id', $claim->id)->set('claim_line_no', '1')->call('openDispute')
        ->assertSet('state', 'VALIDATION_FAILED')->assertHasErrors('description');
    $p = \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\DisputesPage::class)
        ->set('subject_type', 'CLAIM_LINE')->set('claim_id', $claim->id)->set('claim_line_no', '1')->set('reason_code', 'TARIFF_DIFFERENCE')
        ->set('disputed_amount_minor', '3000')->set('description', 'Consultation is contracted at 15000')->call('openDispute')->assertSet('state', 'SUCCESS');
    $d = DB::table('provider_disputes')->where('id', $p->get('selected'))->first();
    expect($d)->not->toBeNull()->and($d->subject_type)->toBe('CLAIM_LINE')->and($d->reason_code)->toBe('TARIFF_DIFFERENCE')->and($d->status)->toBe('SUBMITTED');
    $p->assertSee($d->dispute_number);
});

function gp4Insurer(object $t, array $perms): User
{
    $u = makeAuthTestUser($t->tenant, $perms);
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $t->f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => \App\Application\Health\Preauth\PreauthLifecycle::authorityType(), 'max_amount_minor' => 10_000_000, 'currency' => 'XAF',
        'effective_from' => now()->subMonth()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    test()->actingAs($u, 'web');
    app(\App\Domain\Tenancy\TenantContext::class)->set($t->tenant->id);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

    return $u;
}

it('REQ-HLT-002 web (insurer): pre-authorization queue shows the request and its detail; a line-by-line partial decision runs through PreauthorizationService', function () {
    gp4Web($this);
    $id = \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage::class)
        ->set('request_type', 'OUTPATIENT')->set('policy_id', $this->policy->id)->set('member_ref', $this->f['party']->id)->set('facility_id', $this->main->id)
        ->set('service_code', 'CONS_GP4')->set('quantity', '1')->set('details', ['consultation_date' => '2026-03-10', 'diagnosis_code' => 'J06'])
        ->call('submitRequest')->assertSet('state', 'SUCCESS')->get('selected');
    $pa = DB::table('health_preauthorizations')->find($id);
    $line = DB::table('health_preauthorization_lines')->where('health_preauthorization_id', $id)->first();

    gp4Insurer($this, ['health.preauth.view']);
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthPreauthorizationQueue::class)->assertOk()->assertSee($pa->preauth_number)
        ->assertTableActionHidden('preauthPropose', $id)
        ->mountTableAction('viewDetail', $id)->assertMountedActionModalSee('CONS_GP4');

    gp4Insurer($this, ['health.preauth.view', 'health.preauth.review']);
    $reduced = intdiv((int) $line->insurer_amount_minor, 2);
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthPreauthorizationQueue::class)
        ->callTableAction('preauthPropose', $id, ['decision' => 'PARTIAL', 'valid_until' => '2026-03-14',
            'lines' => [['line_id' => $line->id, 'summary' => 'x', 'approved_quantity' => 1, 'approved_amount_minor' => $reduced, 'decline_reason' => 'Protocol cap']]])
        ->assertHasNoTableActionErrors();
    $l = DB::table('health_preauthorization_lines')->find($line->id);
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBeIn(['PENDING_APPROVAL', 'REFERRED'])
        ->and($l->line_decision)->toBe('PARTIAL')->and((int) $l->approved_amount_minor)->toBe($reduced)->and($l->decline_reason)->toBe('Protocol cap');
});

it('REQ-HLT-003 web (insurer): provider claim review, line-by-line adjudication, payable, settlement batch create and pay via the shared actions', function () {
    $claim = app(ProviderClaimService::class)->create($this->tenant->id, ['provider_id' => $this->clinic->id, 'contract_id' => $this->contract->id, 'invoice_reference' => 'ADJ-1',
        'service_date' => '2026-03-10', 'policy_id' => $this->policy->id, 'member_party_id' => $this->f['party']->id,
        'lines' => [['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000], ['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000]]], $this->user->id);
    app(ProviderClaimService::class)->submit($this->tenant->id, $claim->id, $this->user->id);

    gp4Insurer($this, ['health.provider_claims.view']);
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderClaimQueue::class)->assertSee('ADJ-1')->assertTableActionHidden('providerClaimReview', $claim->id);

    gp4Insurer($this, ['health.provider_claims.view', 'health.provider_claims.adjudicate', 'health.provider_claims.approve_payment',
        'health.provider_settlements.manage', 'health.provider_settlements.pay']);
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderClaimQueue::class)->callTableAction('providerClaimReview', $claim->id);
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('UNDER_REVIEW');
    // A rejected line without a reason code is refused by the form.
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderClaimQueue::class)
        ->callTableAction('providerClaimAdjudicate', $claim->id, ['lines' => [['line_no' => 1, 'summary' => 'a', 'reject' => false], ['line_no' => 2, 'summary' => 'b', 'reject' => true, 'reason_code' => null]]])
        ->assertHasTableActionErrors();
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('UNDER_REVIEW');
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderClaimQueue::class)
        ->callTableAction('providerClaimAdjudicate', $claim->id, ['note' => 'Line 2 duplicate', 'lines' => [
            ['line_no' => 1, 'summary' => 'a', 'reject' => false, 'allowed_minor' => null],
            ['line_no' => 2, 'summary' => 'b', 'reject' => true, 'reason_code' => 'DUPLICATE', 'explanation' => 'Billed twice'],
        ]])->assertHasNoTableActionErrors();
    $lines = DB::table('health_provider_claim_lines')->where('health_provider_claim_id', $claim->id)->orderBy('line_no')->get();
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('PARTIALLY_APPROVED')
        ->and($lines[1]->reason_code)->toBe('DUPLICATE')->and((int) $lines[1]->allowed_minor)->toBe(0)->and((int) $lines[0]->allowed_minor)->toBeGreaterThan(0);
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderClaimQueue::class)->callTableAction('providerClaimPayable', $claim->id);
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('PAYABLE');

    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderSettlements::class)
        ->callTableAction('settlementCreate', data: ['provider_id' => $this->clinic->id, 'currency' => 'XAF'])->assertHasNoTableActionErrors();
    $batch = DB::table('health_provider_settlement_batches')->where('provider_profile_id', $this->clinic->id)->first();
    expect($batch)->not->toBeNull()->and($batch->status)->toBe('OPEN');
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderSettlements::class)->assertSee($batch->batch_number)
        ->mountTableAction('viewDetail', $batch->id)->assertMountedActionModalSee('ADJ-1');
    \Livewire\Livewire::test(\App\Filament\Admin\Pages\HealthProviderSettlements::class)->callTableAction('settlementPay', $batch->id, ['payment_reference' => 'VIR-R2']);
    expect(DB::table('health_provider_settlement_batches')->where('id', $batch->id)->value('status'))->toBe('PAID')
        ->and(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('PAID');
});

it('REQ-PRV-003 web: a provider form action on a real /livewire/update round-trip keeps the panel tenant (ScopesPanelTenant)', function () {
    $this->actingAs($this->user, 'web');
    app(\App\Domain\Tenancy\TenantContext::class)->clear();
    $html = $this->get(\App\Application\Providers\Workspace\Filament\Pages\ReconciliationsPage::getUrl(panel: 'provider'))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
    $snapshot = collect($m[1])->map(fn ($s) => json_decode(html_entity_decode($s, ENT_QUOTES), true))
        ->first(fn ($s) => str_contains((string) ($s['memo']['name'] ?? ''), 'reconciliations-page'));
    expect($snapshot)->not->toBeNull();
    app(\App\Domain\Tenancy\TenantContext::class)->clear();

    $this->withHeaders(['X-Livewire' => 'true'])->postJson(app(\Livewire\Mechanisms\HandleRequests\HandleRequests::class)->getUpdateUri(), [
        '_token' => csrf_token(),
        'components' => [['snapshot' => json_encode($snapshot),
            'updates' => ['payment_reference' => 'VIR-RT', 'received_on' => '2026-04-03', 'currency' => 'XAF', 'amount_minor' => '7000'],
            'calls' => [['path' => '', 'method' => 'recordPayment', 'params' => []]]]],
    ])->assertOk();
    expect(DB::table('provider_reconciliations')->where('payment_reference', 'VIR-RT')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});
