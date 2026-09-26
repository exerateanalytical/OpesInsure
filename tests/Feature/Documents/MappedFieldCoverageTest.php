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
    // Remaining groups (endorsement, cancellation, claim assessment/decision, preauth lines, treaty/recoveries, bordereaux,
    // sticker, consent, proposal declarations/answers ...): one fixture row per mapped table, anchored on this policy/claim.
    $extraSources = seedMappedFixtureRows($policy, $claim, $f, ['health_preauthorizations' => $preauth, 'settlement_batches' => $batch, 'partner_statements' => $statement, 'regulatory_report_runs' => $run, 'provider_contracts' => $contract->id, 'provider_profiles' => $clinic->id]);
    $fields = app(\App\Application\Documents\Security\DocumentFieldRequirements::class);

    $resolved = array_filter($fields->resolve($policy, [], null, [], ["payment" => $f["payment"]->refresh(), "claim" => $claim]), fn ($v) => $v !== null)
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['health_preauthorization_id' => $preauth]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['settlement_batch_id' => $batch]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['partner_statement_id' => $statement]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'sources' => ['regulatory_report_run_id' => $run]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['provider_contract_id' => $contract->id]])
        + MappedFieldValues::resolve($transient, ['tenant_id' => $f['tenant']->id, 'provider_id' => $clinic->id, 'sources' => ['provider_tariff_version_id' => $tariff->id, 'provider_contract_id' => $contract->id]])
        + MappedFieldValues::resolve($policy, ['claim' => $claim, 'payment' => $f['payment'], 'sources' => $extraSources]);

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
        ->and($n)->toBe($u['source_exists']); // every key whose source exists resolves
});

/**
 * One fixture row for every table a mapped source names that the fixture has not already created, anchored on the
 * policy / claim / proposal / quote / party in play (or passed back as ctx sources for tables without such a column).
 * Columns come from information_schema; enum CHECK constraints are honoured by picking their first allowed literal.
 * FK triggers are off only while seeding. Values are fixture data.
 *
 * @param  array<string, string>  $existing  table => id of rows the test already created
 * @return array<string, string> ctx sources (<singular>_id => id)
 */
function seedMappedFixtureRows(Policy $policy, \App\Models\Claim $claim, array $f, array $existing): array
{
    $skip = ['policies', 'parties', 'claims', 'quotes', 'quote_offers', 'proposals', 'payment_intents', 'carriers', 'tenants', 'documents', 'document_types',
        'insurance_products', 'renewal_cases', 'beneficiary_designations', 'cargo_declarations', 'facultative_placements', 'health_members', 'claim_settlements'];
    $refs = collect(\App\Application\DocumentCatalogue\DetailedFieldSourceMap::references());
    $byTable = $refs->groupBy('table')->map(fn ($r) => $r->pluck('column')->filter()->unique()->values()->all())->all();
    $anchors = ['policy_id' => $policy->id, 'claim_id' => $claim->id, 'proposal_id' => $policy->proposal_id, 'quote_id' => $policy->proposal?->offer?->quote_id,
        'quote_offer_id' => $policy->proposal?->offer?->id, 'party_id' => $policy->party_id, 'payment_intent_id' => $policy->payment_intent_id,
        'tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'insurance_product_id' => $f['product']->id];
    $ids = $existing + ['parties' => $policy->party_id, 'carriers' => $f['carrier']->id, 'insurance_products' => $f['product']->id, 'policies' => $policy->id, 'claims' => $claim->id,
        'proposals' => $policy->proposal_id, 'quotes' => $anchors['quote_id'], 'quote_offers' => $anchors['quote_offer_id']];
    // FK arrows "t.fk -> t2.col": seed the target first, then point the fk at it.
    $arrows = [];
    foreach (\App\Application\DocumentCatalogue\DetailedFieldSourceMap::MAP as [$key, $source]) {
        foreach (preg_split('#\s+/\s+#', (string) $source) as $alt) {
            if (preg_match('/^(\w+)\.(\w+)\s*->\s*(\w+)\./', trim((string) preg_replace('/\s*\(.*$/', '', $alt)), $m)) {
                $arrows[$m[1]][$m[2]] = $m[3];
            }
        }
    }
    $order = array_keys($byTable);
    usort($order, fn ($a, $b) => (isset($arrows[$a]) <=> isset($arrows[$b])));
    $fac = DB::table('facultative_placements')->where('policy_id', $policy->id)->value('id');
    // Rows whose CHECK constraints tie several columns together (shape / lineage / amount rules).
    $overrides = [
        'product_exclusions' => ['level' => 'PRODUCT'], 'sla_clocks' => ['deadline_label' => 'PLATFORM_SLA'], 'cashier_collections' => ['payer_name' => 'Fixture payer'],
        'claim_assignments' => ['assignment_type' => 'HANDLER', 'assignee_id' => (string) Str::uuid(), 'status' => null], 'claim_decisions' => ['kind' => 'ORIGINAL', 'appeal_of_decision_id' => null],
        'health_benefit_rules' => ['medical_service_code' => 'CONS'], 'health_benefit_schedules' => ['copay_bp' => 2000, 'period_basis' => 'POLICY_YEAR', 'scope' => 'INDIVIDUAL', 'status' => 'ACTIVE'],
        'reinsurance_cessions' => ['source' => 'FACULTATIVE', 'facultative_placement_id' => $fac], 'reinsurance_recoveries' => ['billed_minor' => 20000, 'settled_minor' => 12345],
    ];
    $sources = [];
    DB::statement("SET session_replication_role = 'replica'");
    foreach ($order as $table) {
        if (in_array($table, $skip, true) || isset($ids[$table]) || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            continue;
        }
        $cols = DB::select('select column_name, data_type, is_nullable, column_default, character_maximum_length as len from information_schema.columns where table_schema = ? and table_name = ?', ['public', $table]);
        $checks = collect(DB::select("select pg_get_constraintdef(c.oid) as def from pg_constraint c join pg_class t on t.oid = c.conrelid where t.relname = ? and c.contype = 'c'", [$table]))->pluck('def');
        $row = [];
        foreach ($cols as $c) {
            $name = $c->column_name;
            $mapped = in_array($name, $byTable[$table], true);
            $allowed = null;
            foreach ($checks as $def) {
                $allowed ??= mappedFixtureAllowed($name, $def);
            }
            $value = match (true) {
                $name === 'id' => (string) Str::uuid(),
                isset($anchors[$name]) => $anchors[$name],
                isset($arrows[$table][$name], $ids[$arrows[$table][$name]]) => $ids[$arrows[$table][$name]],
                str_contains($name, 'currency') => 'XAF',
                $allowed !== null && in_array($c->data_type, ['text', 'character varying', 'character'], true) && ($mapped || $c->is_nullable === 'NO') => $allowed,
                ! $mapped && ($c->is_nullable === 'YES' || $c->column_default !== null) => null,
                default => mappedFixtureValue($name, $c->data_type, $c->len ? (int) $c->len : null),
            };
            if ($value !== null || $name === 'id') {
                $row[$name] = $value;
            }
        }
        $row = ($overrides[$table] ?? []) + $row;
        try {
            DB::transaction(fn () => DB::table($table)->insert($row));
            $ids[$table] = $row['id'] ?? null;
            $singular = preg_replace(['/ies$/', '/(x|ss|ch)es$/', '/s$/'], ['y', '$1', ''], $table);
            if (isset($row['id']) && ! array_intersect(array_keys($row), ['policy_id', 'claim_id', 'proposal_id', 'quote_id', 'quote_offer_id', 'payment_intent_id'])) {
                $sources[$singular.'_id'] = $row['id'];
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "FIXTURE_SKIP {$table}: ".Str::limit($e->getMessage(), 160)."\n");
        }
    }
    $fillOverrides = ['claim_settlements' => ['remaining_limit_minor' => 99_999_999]];
    // Rows the fixture already created (policy, claim, offer, preauth, settlement, statement, run ...): fill their empty mapped columns.
    foreach ($byTable as $table => $mappedCols) {
        if (in_array($table, ['parties', 'tenants', 'carriers', 'documents', 'document_types'], true) || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            continue;
        }
        $anchor = null;
        foreach (['policy_id', 'claim_id', 'proposal_id', 'quote_id'] as $col) {
            if (! empty($anchors[$col]) && \Illuminate\Support\Facades\Schema::hasColumn($table, $col)) {
                $anchor = [$col, $anchors[$col]];
                break;
            }
        }
        $id = $ids[$table] ?? null;
        if (! $anchor && ! $id) {
            continue;
        }
        $rows = $anchor ? DB::table($table)->where($anchor[0], $anchor[1])->get() : DB::table($table)->where('id', $id)->get();
        $types = collect(DB::select('select column_name, data_type, character_maximum_length as len from information_schema.columns where table_schema = ? and table_name = ?', ['public', $table]))->keyBy('column_name');
        foreach ($rows as $r) {
            $set = [];
            foreach ($mappedCols as $c) {
                if (! isset($types[$c]) || ! property_exists($r, $c) || $c === 'id' || ! in_array($r->{$c}, [null, '[]', '{}'], true)) {
                    continue;
                }
                if (str_ends_with($c, '_id')) {
                    $fk = $anchors[$c] ?? ((isset($arrows[$table][$c]) ? ($ids[$arrows[$table][$c]] ?? null) : null));
                    if ($fk) {
                        $set[$c] = $fk;
                    }
                } else {
                    $allowedFill = null;
                    foreach (DB::select("select pg_get_constraintdef(c.oid) as def from pg_constraint c join pg_class t on t.oid = c.conrelid where t.relname = ? and c.contype = 'c'", [$table]) as $ck) {
                        $allowedFill ??= mappedFixtureAllowed($c, $ck->def);
                    }
                    $set[$c] = $allowedFill ?? ($fillOverrides[$table][$c] ?? null) ?? (str_contains($c, 'currency') ? 'XAF' : mappedFixtureValue($c, $types[$c]->data_type, $types[$c]->len ? (int) $types[$c]->len : null));
                }
            }
            if ($set !== [] && isset($r->id)) {
                try {
                    DB::transaction(fn () => DB::table($table)->where('id', $r->id)->update($set));
                } catch (\Throwable $e) {
                    fwrite(STDERR, "FIXTURE_FILL_SKIP {$table}: ".Str::limit($e->getMessage(), 140)."\n");
                }
            }
        }
    }
    DB::statement("SET session_replication_role = 'origin'");

    return $sources;
}

function mappedFixtureValue(string $name, string $type, ?int $len = null): mixed
{
    $text = str_replace('_', ' ', $name).' fixture';

    return match (true) {
        $type === 'uuid' => (string) Str::uuid(),
        in_array($type, ['jsonb', 'json'], true) => json_encode(['fixture' => str_replace('_', ' ', $name)]),
        $type === 'boolean' => true,
        in_array($type, ['integer', 'bigint', 'smallint'], true) => 12345,
        in_array($type, ['numeric', 'double precision', 'real'], true) => 12.5,
        $type === 'date' => now()->toDateString(),
        str_starts_with($type, 'timestamp') => now(),
        $type === 'ARRAY' => '{}',
        default => mb_substr($len !== null && $len < 8 ? strtoupper($name) : $text, 0, $len ?? 60),
    };
}

/** First literal a CHECK constraint allows for this column ("(col)::text = ANY (ARRAY['A' ..." or "(col)::text = 'A'"). */
function mappedFixtureAllowed(string $col, string $def): ?string
{
    $q = preg_quote($col, '/');

    return preg_match("/\(?\b{$q}\)?::text = (?:ANY \(\(ARRAY\[)?'([^']+)'/", $def, $m) ? $m[1] : null;
}
