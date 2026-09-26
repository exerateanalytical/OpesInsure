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
