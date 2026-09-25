<?php

declare(strict_types=1);

/*
 | Batch 17 B5 — REQ-IMP-002 legacy integration & data migration (BP W26, MPS §92).
 | stage → validate → dry-run → reconcile → submit → approve (maker-checker) → commit; rollback of uncommitted batches;
 | idempotent by legacy id (external_record_mappings); opening balances on migration.opening_balance or CONFIG_REQUIRED.
 */

use App\Application\Import\Legacy\LegacyMigrationPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\Carrier;
use App\Models\ExternalRecordMapping;
use App\Models\Import\ImportBatch;
use App\Models\InsuranceProduct;
use App\Models\IntegrationClient;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B5_MAKER = ['legacy_migration.manage'];
const B5_CHECKER = ['legacy_migration.manage', 'legacy_migration.approve', 'legacy_migration.commit'];

function b5Csv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'b5').'.csv';
    $h = fopen($path, 'w');
    fputcsv($h, array_keys($rows[0]), ';');
    foreach ($rows as $r) {
        fputcsv($h, array_values($r), ';');
    }
    fclose($h);

    return $path;
}

function b5World(): array
{
    $tenant = makeAuthTestTenant('B5');
    app(TenantContext::class)->set($tenant->id);
    $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B5 Carrier', 'status' => 'ACTIVE'])->id, 'cima_code' => 'B5CAR', 'status' => 'ACTIVE']);
    $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'AUTO', 'code' => 'B5AUTO', 'name' => 'Auto', 'version' => 1, 'effective_from' => '2020-01-01', 'status' => 'ACTIVE']);
    $source = IntegrationClient::create(['name' => 'LEGACY-ASSUR', 'client_id' => 'legacy-'.Str::random(8), 'client_secret_hash' => 'x', 'scopes' => ['migration'], 'status' => 'ACTIVE']);
    $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B5 Broker', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE', 'licence_number' => 'LIC-B5']);

    return ['tenant' => $tenant, 'carrier' => $carrier, 'product' => $product, 'source' => $source, 'partner' => $partner,
        'maker' => makeAuthTestUser($tenant, B5_MAKER, 'B5_MAKER'), 'checker' => makeAuthTestUser($tenant, B5_CHECKER, 'B5_CHECKER')];
}

function b5Profile(array $w): void
{
    $acc = fn ($code, $type) => tap((string) Str::uuid(), fn ($id) => DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $w['tenant']->id, 'code' => $code, 'name' => $code,
        'type' => $type, 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]));
    DB::table('financial_posting_profiles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $w['tenant']->id, 'event_type' => 'migration.opening_balance', 'currency' => 'XAF',
        'debit_account_id' => $acc('B5-AR', 'ASSET'), 'credit_account_id' => $acc('B5-MIG', 'LIABILITY'), 'status' => 'APPROVED', 'created_by' => $w['checker']->id]);
}

/** Stage → dry-run → reconcile → submit → approve → commit. */
function b5Migrate(array $w, string $entity, array $rows, ?array $totals = null): ImportBatch
{
    $p = app(LegacyMigrationPipeline::class);
    $b = $p->stage($entity, $w['source']->id, b5Csv($rows), 'extract.csv', $w['maker'], $w['tenant']->id, [], $totals);
    expect($b->status)->toBe('VALIDATED', json_encode($b->report));
    $b = $p->reconcile($p->dryRun($b, $w['maker']));
    expect($b->status)->toBe('RECONCILED', json_encode($b->reconciliation));
    $b = $p->approve($p->submit($b, $w['maker']), $w['checker']);

    return $p->commit($b, $w['checker']);
}

function b5Customers(): array
{
    return [
        ['legacy_id' => 'C1', 'type' => 'INDIVIDUAL', 'display_name' => 'Jean Mbarga', 'date_of_birth' => '1980-02-01', 'phone' => '+237690000001', 'email' => 'jean@example.cm'],
        ['legacy_id' => 'C2', 'type' => 'ORGANIZATION', 'display_name' => 'SARL Douala Fret', 'date_of_birth' => '', 'phone' => '', 'email' => ''],
    ];
}

function b5Policies(): array
{
    return [
        ['legacy_id' => 'P1', 'customer_legacy_id' => 'C1', 'carrier_code' => 'B5CAR', 'product_code' => 'B5AUTO', 'policy_number' => 'LEG-POL-1', 'starts_at' => '2026-01-01',
            'ends_at' => '2027-01-01', 'premium' => '120000', 'currency' => 'XAF', 'status' => 'ACTIVE', 'coverages' => 'TPL:100000|FIRE:20000'],
        ['legacy_id' => 'P2', 'customer_legacy_id' => 'C2', 'carrier_code' => 'B5CAR', 'product_code' => 'B5AUTO', 'policy_number' => 'LEG-POL-2', 'starts_at' => '2026-03-01',
            'ends_at' => '2027-03-01', 'premium' => '80000', 'currency' => 'XAF', 'status' => 'ACTIVE', 'coverages' => ''],
    ];
}

it('REQ-IMP-002 migrates customers → policies → premiums end to end; the dry run persists nothing; commit is idempotent by legacy id', function () {
    $w = b5World();
    b5Profile($w);
    $p = app(LegacyMigrationPipeline::class);

    // Dry run executes the adapters but rolls everything back.
    $parties = Party::count();
    $b = $p->stage('legacy.customers', $w['source']->id, b5Csv(b5Customers()), 'customers.csv', $w['maker'], $w['tenant']->id, [], ['count' => 2]);
    $b = $p->dryRun($b, $w['maker']);
    expect($b->status)->toBe('DRY_RUN_OK')->and($b->dry_run['totals']['count'])->toBe(2)
        ->and(Party::count())->toBe($parties)->and(ExternalRecordMapping::count())->toBe(0);
    $p->rollback($b, $w['maker'], 'Re-staged below');

    $c = b5Migrate($w, 'legacy.customers', b5Customers(), ['count' => 2]);
    expect($c->status)->toBe('COMMITTED')->and($c->imported_count)->toBe(2)->and($c->reconciliation['balanced'])->toBeTrue()
        ->and($c->reconciliation['migrated']['count'])->toBe(2)
        ->and(ExternalRecordMapping::where(['integration_client_id' => $w['source']->id, 'record_type' => 'legacy.customer'])->count())->toBe(2);

    $pol = b5Migrate($w, 'legacy.policies', b5Policies(), ['count' => 2, 'amount_minor' => 200000]);
    expect($pol->status)->toBe('COMMITTED')->and($pol->reconciliation['migrated']['amount_minor'])->toBe(200000);
    $policyId = ExternalRecordMapping::where(['record_type' => 'legacy.policy', 'external_record_id' => 'P1'])->value('opesinsure_record_id');
    $policy = DB::table('policies')->find($policyId);
    expect($policy->policy_number)->toBe('LEG-POL-1')->and($policy->data_origin)->toBe('LEGACY_MIGRATION')->and((int) $policy->premium_minor)->toBe(120000);
    $version = DB::table('policy_versions')->where('policy_id', $policyId)->first();
    expect($version->kind)->toBe('BACKFILL')->and($version->source_type)->toBe('legacy_migration');
    expect(DB::table('proposals')->where('id', $policy->proposal_id)->value('status'))->toBe('MIGRATED');

    $prem = b5Migrate($w, 'legacy.premiums', [
        ['legacy_id' => 'R1', 'policy_legacy_id' => 'P1', 'amount' => '45000', 'currency' => 'XAF', 'due_at' => '2026-02-01'],
    ], ['count' => 1, 'amount_minor' => 45000]);
    $ob = $prem->result['opening_balances'][0];
    expect($ob['status'])->toBe('POSTED')->and($ob['event'])->toBe('migration.opening_balance');
    $obl = DB::table('financial_obligations')->find($ob['record_id']);
    expect($obl->kind)->toBe('RECEIVABLE')->and($obl->type)->toBe('PREMIUM')->and((int) $obl->outstanding_minor)->toBe(45000)->and($obl->policy_id)->toBe($policyId);
    expect(DB::table('journals')->where(['reference_type' => 'migration.opening_balance', 'reference_id' => $obl->id, 'status' => 'POSTED'])->exists())->toBeTrue();
    expect(DB::table('outbox_messages')->where('event_name', 'legacy_migration.committed')->count())->toBe(3);

    // Same extract again: every legacy id is already mapped → nothing new, nothing duplicated.
    $again = $p->stage('legacy.customers', $w['source']->id, b5Csv(b5Customers()), 'customers.csv', $w['maker'], $w['tenant']->id);
    expect($again->status)->toBe('VALIDATED')->and($again->report['valid'])->toBe(0)->and($again->report['already_migrated'])->toHaveCount(2);
    $again = $p->dryRun($again, $w['maker']);
    expect($again->dry_run['skipped'])->toHaveCount(2)->and($again->dry_run['created'])->toBe([]);
    expect(ExternalRecordMapping::where('record_type', 'legacy.customer')->count())->toBe(2);
});

it('REQ-IMP-002 golden record: a legacy customer matching an existing party is flagged for human-approved merge, never merged or overwritten', function () {
    $w = b5World();
    $existing = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Jean Mbarga', 'date_of_birth' => '1980-02-01', 'status' => 'ACTIVE']);
    PartyContact::create(['party_id' => $existing->id, 'type' => 'PHONE', 'normalized_value' => '+237690000001', 'is_primary' => true]);

    $c = b5Migrate($w, 'legacy.customers', [b5Customers()[0]]);
    $created = $c->result['created'][0];
    expect($created['id'])->not->toBe($existing->id)->and($created['notes']['contact_hits'])->toBe(['PHONE']);
    expect(Party::whereKey($existing->id)->value('display_name'))->toBe('Jean Mbarga');
    expect(DB::table('party_contacts')->where('normalized_value', '+237690000001')->count())->toBe(1);
    // Any merge is a separate entity.merge approval, never done by the migration.
    expect(DB::table('approval_requests')->where('action_code', 'entity.merge')->where('status', 'APPROVED')->exists())->toBeFalse();
});

it('REQ-IMP-002 claims + reserves and commission balances: opening balances are CONFIG_REQUIRED without a migration.opening_balance mapping', function () {
    $w = b5World();
    b5Migrate($w, 'legacy.customers', b5Customers());
    b5Migrate($w, 'legacy.policies', b5Policies());

    $cl = b5Migrate($w, 'legacy.claims', [
        ['legacy_id' => 'K1', 'policy_legacy_id' => 'P1', 'claim_number' => 'LEG-CLM-1', 'status' => 'ASSESSMENT', 'loss_occurred_at' => '2026-05-10', 'currency' => 'XAF',
            'reserve_indemnity' => '300000', 'reserve_expense' => '25000', 'description' => 'Collision'],
    ], ['count' => 1, 'amount_minor' => 325000]);
    $claimId = $cl->result['created'][0]['id'];
    expect((int) DB::table('claims')->where('id', $claimId)->value('current_reserve_minor'))->toBe(325000);
    $reserves = DB::table('claim_reserve_changes')->where('claim_id', $claimId)->orderBy('approval_seq')->get();
    expect($reserves)->toHaveCount(2)->and($reserves[0]->reserve_head)->toBe('INDEMNITY')->and($reserves[0]->reserve_stage)->toBe('INITIAL')
        ->and($reserves[0]->requested_by)->toBe($w['maker']->id)->and($reserves[0]->approved_by)->toBe($w['checker']->id);
    expect($cl->result['opening_balances'][0]['status'])->toBe('CONFIG_REQUIRED')->and($cl->result['totals']['config_required'])->toBe(1);

    $cm = b5Migrate($w, 'legacy.commission_balances', [
        ['legacy_id' => 'M1', 'partner_licence_number' => 'LIC-B5', 'amount' => '15000', 'currency' => 'XAF', 'due_at' => '2026-06-30', 'policy_legacy_id' => 'P2'],
    ]);
    $obl = DB::table('financial_obligations')->find($cm->result['created'][0]['id']);
    expect($obl->kind)->toBe('PAYABLE')->and($obl->type)->toBe('COMMISSION')->and($obl->creditor_id)->toBe($w['partner']->id);
    expect($cm->result['opening_balances'][0]['status'])->toBe('CONFIG_REQUIRED');
    expect(DB::table('journals')->where('reference_type', 'migration.opening_balance')->exists())->toBeFalse();
});

it('REQ-IMP-002 validation names the broken rule; control-total mismatches block submission; uncommitted batches roll back, committed ones do not', function () {
    $w = b5World();
    $p = app(LegacyMigrationPipeline::class);
    $bad = $p->stage('legacy.policies', $w['source']->id, b5Csv([
        ['legacy_id' => 'P9', 'customer_legacy_id' => 'NOPE', 'carrier_code' => 'B5CAR', 'product_code' => 'B5AUTO', 'policy_number' => 'X-1', 'starts_at' => '2026-01-01',
            'ends_at' => '2025-01-01', 'premium' => '10.5', 'currency' => 'XAF', 'status' => 'ACTIVE', 'coverages' => ''],
        ['legacy_id' => 'P9', 'customer_legacy_id' => 'NOPE', 'carrier_code' => 'ZZZ', 'product_code' => 'B5AUTO', 'policy_number' => 'X-2', 'starts_at' => '2026-01-01',
            'ends_at' => '2027-01-01', 'premium' => '10', 'currency' => 'XAF', 'status' => 'ACTIVE', 'coverages' => ''],
    ]), 'p.csv', $w['maker'], $w['tenant']->id);
    expect($bad->status)->toBe('FAILED');
    $rules = collect($bad->report['errors'])->pluck('rule')->all();
    expect($rules)->toContain('CUSTOMER_MIGRATED', 'PERIOD', 'AMOUNT', 'LEGACY_ID_UNIQUE');
    expect(fn () => $p->dryRun($bad, $w['maker']))->toThrow(ValidationException::class);

    $b = $p->stage('legacy.customers', $w['source']->id, b5Csv(b5Customers()), 'c.csv', $w['maker'], $w['tenant']->id, [], ['count' => 3]);
    $b = $p->reconcile($p->dryRun($b, $w['maker']));
    expect($b->status)->toBe('UNRECONCILED')->and($b->reconciliation['differences'][0]['check'])->toBe('control_totals.count');
    expect(fn () => $p->submit($b, $w['maker']))->toThrow(ValidationException::class);
    $b = $p->rollback($b, $w['maker'], 'Extract incomplete');
    expect($b->status)->toBe('ROLLED_BACK')->and($b->rolled_back_by)->toBe($w['maker']->id);

    // Maker-checker: the maker cannot approve their own batch; commit needs approval first.
    $b = $p->stage('legacy.customers', $w['source']->id, b5Csv(b5Customers()), 'c.csv', $w['maker'], $w['tenant']->id);
    $b = $p->submit($p->reconcile($p->dryRun($b, $w['maker'])), $w['maker']);
    expect(fn () => $p->commit($b, $w['checker']))->toThrow(ValidationException::class);
    expect(fn () => $p->approve($b, $w['maker']))->toThrow(ValidationException::class);
    $b = $p->commit($p->approve($b->refresh(), $w['checker']), $w['checker']);
    expect($b->status)->toBe('COMMITTED');
    expect(fn () => $p->rollback($b, $w['maker'], 'too late'))->toThrow(ValidationException::class);
});

it('REQ-IMP-002 exposes the legacy migration API with permissions and tenant isolation', function () {
    $w = b5World();
    Passport::actingAs($w['maker']);
    $file = UploadedFile::fake()->createWithContent('customers.csv', file_get_contents(b5Csv(b5Customers())));
    $res = $this->postJson('/api/v1/legacy-migrations', ['entity' => 'legacy.customers', 'integration_client_id' => $w['source']->id, 'file' => $file,
        'control_totals' => ['count' => 2]], tenantHeader($w['tenant']))->assertCreated();
    $id = $res->json('data.id');
    expect($res->json('data.status'))->toBe('VALIDATED');
    $this->postJson("/api/v1/legacy-migrations/$id/dry-run", [], tenantHeader($w['tenant']))->assertOk()->assertJsonPath('data.status', 'DRY_RUN_OK');
    $this->postJson("/api/v1/legacy-migrations/$id/reconcile", [], tenantHeader($w['tenant']))->assertOk()->assertJsonPath('data.reconciliation.balanced', true);
    $this->postJson("/api/v1/legacy-migrations/$id/submit", [], tenantHeader($w['tenant']))->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    $this->postJson("/api/v1/legacy-migrations/$id/approve", [], tenantHeader($w['tenant']))->assertForbidden();

    Passport::actingAs($w['checker']);
    $this->postJson("/api/v1/legacy-migrations/$id/approve", [], tenantHeader($w['tenant']))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->postJson("/api/v1/legacy-migrations/$id/commit", [], tenantHeader($w['tenant']))->assertOk()->assertJsonPath('data.status', 'COMMITTED');
    $this->getJson('/api/v1/legacy-migrations/entities', tenantHeader($w['tenant']))->assertOk()->assertJsonCount(5, 'data');

    $other = makeAuthTestTenant('B5X');
    Passport::actingAs(makeAuthTestUser($other, B5_CHECKER, 'B5X'));
    $this->getJson("/api/v1/legacy-migrations/$id", tenantHeader($other))->assertNotFound();
    // The generic REQ-IMP-001 endpoints do not see legacy batches.
    Passport::actingAs(makeAuthTestUser($w['tenant'], ['imports.create'], 'B5_IMP'));
    $this->getJson("/api/v1/imports/$id", tenantHeader($w['tenant']))->assertNotFound();
});
