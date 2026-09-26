<?php

declare(strict_types=1);

// D2 follow-up (DOCUMENT_SECURITY_COMPLETION_PLAN): how many mapped field keys resolve at issuance on seeded fixtures.

use App\Application\Documents\Security\MappedFieldValues;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

it('D2 REQ-DOC-SEC-D2: mapped keys resolve from the issuance context (policy flow + provider contract / tariff flows)', function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(\App\Application\Payments\WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor, 'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $policy = app(PolicyIssuanceService::class)->approve(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail(),
        ['policy_number' => 'POL-CV-'.Str::random(6), 'carrier_reference' => 'CR'], \App\Models\User::create(['full_name' => 'Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']));
    $policy = Policy::with(['carrier.party', 'party', 'proposal.offer.product', 'proposal.offer.quote'])->findOrFail($policy->id);
    DB::table('renewal_cases')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'due_on' => now()->addYear()->toDateString(), 'status' => 'DUE', 'created_at' => now(), 'updated_at' => now()]);

    // Provider flows (no policy): contract + tariff, as ProviderDocumentService passes them in ctx['sources'].
    $net = app(ProviderNetworkService::class);
    $svc = $net->addMedicalService(['code' => 'CONS_CV', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    $reg = app(ProviderRegistry::class);
    $clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique CV', 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($clinic->id, $to, null, null, null);
    }
    $reg->addFacility($clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $network = $net->createNetwork($f['tenant']->id, ['code' => 'CVNET', 'name' => 'CV network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH', 'carrier_id' => $f['carrier']->id], null);
    $net->addMember($f['tenant']->id, $network->id, ['provider_id' => $clinic->id, 'effective_from' => '2026-01-01'], null);
    $contract = $net->createContract($f['tenant']->id, $network->id, ['provider_id' => $clinic->id, 'contract_number' => 'CV-001', 'effective_from' => '2026-01-01'], null);
    $tariff = $net->draftTariff($f['tenant']->id, $contract->id, '2026-01-01', 'XAF', [['medical_service_id' => $svc->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $transient = (new Policy())->forceFill(['tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'currency' => 'XAF', 'version' => 0]);

    $f['quote']->update(['quote_number' => 'QT-CV-'.Str::random(5)]);
    $policy = Policy::with(['carrier.party', 'party', 'proposal.offer.product', 'proposal.offer.quote'])->findOrFail($policy->id);
    $claim = \App\Models\Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-CV-'.Str::random(6),
        'status' => 'CARRIER_REVIEW', 'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'Collision', 'cause' => 'COLLISION'], 'loss_location' => 'Douala',
        'currency' => 'XAF', 'submitted_at' => now(), 'estimated_loss_minor' => 250000]);
    $preauth = (string) Str::uuid();
    DB::table('health_preauthorizations')->insert(['id' => $preauth, 'tenant_id' => $f['tenant']->id, 'provider_profile_id' => $clinic->id, 'preauth_number' => 'PA-CV-'.Str::random(5),
        'request_type' => 'OUTPATIENT', 'policy_id' => $policy->id, 'member_ref' => 'M-CV-1', 'service_date' => now()->toDateString(), 'currency' => 'XAF', 'status' => 'APPROVED',
        'created_at' => now(), 'updated_at' => now()]);
    $batch = (string) Str::uuid();
    DB::table('settlement_batches')->insert(['id' => $batch, 'tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(), 'net_amount_minor' => 4500000, 'currency' => 'XAF', 'status' => 'PENDING_APPROVAL', 'prepared_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    // Domain rows for the remaining mapped groups (FK triggers off only while seeding; values are fixture data).
    DB::statement("SET session_replication_role = 'replica'");
    $t = $f['tenant']->id;
    $u = $f['user']->id;
    $now = now();
    DB::table('beneficiary_designations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t, 'policy_id' => $policy->id, 'set_version' => 1, 'designation' => 'PRIMARY', 'full_name' => 'Ada Beneficiary',
        'allocation_pct' => 100, 'effective_from' => $now, 'designated_by' => $u, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('cargo_declarations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t, 'profile_id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'sequence' => 1, 'reference' => 'CG-1',
        'conveyance' => 'SEA', 'goods_description' => 'Cocoa', 'origin' => 'Douala', 'destination' => 'Antwerp', 'shipment_date' => $now->toDateString(), 'insured_value_minor' => 10_000_000,
        'rate_bps' => 50, 'premium_minor' => 50_000, 'declared_by' => $u, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('facultative_placements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t, 'policy_id' => $policy->id, 'reference' => 'FAC-1', 'risk_description' => 'Warehouse', 'currency' => 'XAF',
        'sum_insured_minor' => 900_000_000, 'premium_minor' => 3_000_000, 'placed_share_percent' => 40, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('health_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t, 'policy_id' => $policy->id, 'member_number' => 'HM-1', 'card_number' => 'CARD-1', 'relationship' => 'PRINCIPAL',
        'display_name' => 'Ada Member', 'effective_from' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('claim_settlements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t, 'claim_id' => $claim->id, 'claim_decision_id' => (string) Str::uuid(), 'payee_party_id' => $f['party']->id,
        'reference' => 'STL-1', 'currency' => 'XAF', 'covered_minor' => 200000, 'gross_minor' => 250000, 'amount_minor' => 200000, 'breakdown' => '{}', 'calculated_by' => $u, 'created_at' => $now, 'updated_at' => $now]);
    $statement = (string) Str::uuid();
    DB::table('partner_statements')->insert(['id' => $statement, 'tenant_id' => $t, 'partner_id' => (string) Str::uuid(), 'statement_number' => 'PS-1', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'currency' => 'XAF', 'content_hash' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(), 'prepared_by' => $u, 'created_at' => $now, 'updated_at' => $now]);
    $run = (string) Str::uuid();
    DB::table('regulatory_report_runs')->insert(['id' => $run, 'definition_id' => (string) Str::uuid(), 'period_key' => '2026-Q3', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64),
        'prepared_by' => $u, 'created_at' => $now, 'updated_at' => $now]);
    DB::statement("SET session_replication_role = 'origin'");
    $fields = app(\App\Application\Documents\Security\DocumentFieldRequirements::class);

    $resolved = array_filter($fields->resolve($policy, [], null, [], ["payment" => $f["payment"]->refresh(), "claim" => $claim]), fn ($v) => $v !== null)
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['health_preauthorization_id' => $preauth]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['settlement_batch_id' => $batch]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['partner_statement_id' => $statement]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['regulatory_report_run_id' => $run]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['provider_contract_id' => $contract->id]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['provider_tariff_version_id' => $tariff->id, 'provider_contract_id' => $contract->id]]);

    $u = MappedFieldValues::coverageUniverse();
    $resolved = array_filter($resolved, fn ($v) => $v !== null && $v !== "" && $v !== []);
    $n = count(array_intersect(array_keys($resolved), $u["keys"]));
    fwrite(STDERR, "\nMAPPED_COVERAGE N={$n} of source-exists M={$u['source_exists']} (all mapped keys {$u['mapped']})\n");
    fwrite(STDERR, 'UNRESOLVED: '.implode(', ', array_diff($u['keys'], array_keys($resolved)))."\n");

    // Every resolved value is a non-blank printable value; nothing is read from an unanchored "latest row".
    foreach ($resolved as $k => $v) {
        expect(is_scalar($v) ? trim((string) $v) : $v)->not->toBe('');
    }
    expect(array_filter($resolved, fn ($v) => $v !== null))->toHaveKeys(['quote.number', 'claim.loss_location', 'preauth.number', 'settlement_batch.status', 'beneficiary.name', 'cargo.goods', 'reinsurance.reference', 'member.name', 'settlement.reference', 'statement.period', 'premium.net', 'policy.term', 'provider_contract.number', 'provider.name', 'renewal.due_on'])
        ->and($n)->toBeGreaterThanOrEqual(90);
});
