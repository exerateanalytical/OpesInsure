<?php

declare(strict_types=1);

/*
 * Agent 10-10 — REQ-ACC-005 finance exception centre + finance reports registry, and the Batch 9 permission wiring.
 */

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Identity\RoleCatalogue;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b1010Obligation(Tenant $t, string $kind, int $minor, $dueAt, string $type = 'PREMIUM'): object
{
    return app(ObligationService::class)->create(['tenant_id' => $t->id, 'kind' => $kind, 'type' => $type, 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => $minor, 'due_at' => $dueAt]);
}

function b1010Seed(Tenant $t, User $u): void
{
    b1010Obligation($t, 'RECEIVABLE', 10000, now()->subDays(10));   // 1_30
    b1010Obligation($t, 'RECEIVABLE', 20000, now()->subDays(45));   // 31_60
    b1010Obligation($t, 'PAYABLE', 5000, now()->subDays(100), 'CLAIM'); // 90_PLUS
    b1010Obligation($t, 'RECEIVABLE', 7000, now()->addDays(5));     // CURRENT, not overdue

    $branch = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $t->id, 'code' => 'BR-'.Str::random(5), 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cashier_sessions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'branch_id' => $branch, 'cashier_user_id' => $u->id, 'currency' => 'XAF',
        'opening_float_minor' => 0, 'status' => 'CLOSED', 'opened_at' => now()->subHours(8), 'closed_at' => now(), 'expected_cash_minor' => 5000, 'counted_cash_minor' => 4500,
        'variance_minor' => -500, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('mobile_money_clearing_batches')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'provider' => 'MTN_MOMO', 'settlement_reference' => 'S-'.Str::random(6),
        'settlement_date' => now()->toDateString(), 'currency' => 'XAF', 'expected_minor' => 10000, 'fee_minor' => 100, 'settled_minor' => 9800, 'variance_minor' => -100,
        'status' => 'VARIANCE', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
}

it('REQ-ACC-005 aggregates open finance exceptions per source, tenant-scoped, with overdue aging buckets', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, ['finance.exceptions.view']);
    b1010Seed($t, $u);
    $other = makeAuthTestTenant('other');
    b1010Obligation($other, 'RECEIVABLE', 99999, now()->subDays(10));
    Passport::actingAs($u);

    $d = $this->getJson('/api/v1/finance/exception-centre', tenantHeader($t))->assertOk()->json('data');

    expect(array_keys($d['sources']))->toBe(['issuance_exceptions', 'reconciliation_exceptions', 'refunds_awaiting_action', 'clearing_variances', 'overdue_obligations', 'cashier_sessions_awaiting_approval']);
    foreach ($d['sources'] as $s) {
        expect($s['available'])->toBeTrue();
    }
    $overdue = $d['sources']['overdue_obligations'];
    expect($overdue['count'])->toBe(3)
        ->and($overdue['amounts'])->toBe(['XAF' => 35000])
        ->and($overdue['breakdown']['RECEIVABLE']['XAF']['1_30'])->toBe(['count' => 1, 'outstanding_minor' => 10000])
        ->and($overdue['breakdown']['RECEIVABLE']['XAF']['31_60']['outstanding_minor'])->toBe(20000)
        ->and($overdue['breakdown']['PAYABLE']['XAF']['90_PLUS']['outstanding_minor'])->toBe(5000)
        ->and($overdue['items'][0]['aging_bucket'])->toBe('90_PLUS');
    expect($d['sources']['cashier_sessions_awaiting_approval']['count'])->toBe(1)
        ->and($d['sources']['cashier_sessions_awaiting_approval']['breakdown'])->toBe(['VARIANCE' => 1])
        ->and($d['sources']['clearing_variances']['amounts'])->toBe(['XAF' => -100])
        ->and($d['sources']['issuance_exceptions']['count'])->toBe(0)
        ->and($d['total_open'])->toBe(5);

    $only = $this->getJson('/api/v1/finance/exception-centre?sources[]=clearing_variances', tenantHeader($t))->assertOk()->json('data.sources');
    expect(array_keys($only))->toBe(['clearing_variances']);
});

it('REQ-ACC-005 needs finance.exceptions.view / finance.reports.view', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['ledger.read']));
    $this->getJson('/api/v1/finance/exception-centre', tenantHeader($t))->assertForbidden();
    $this->getJson('/api/v1/finance/reports', tenantHeader($t))->assertForbidden();
    $this->getJson('/api/v1/finance/reports/FR-02', tenantHeader($t))->assertForbidden();
});

it('REQ-ACC-005 registers 20 finance reports, flags the ones without data as NOT_AVAILABLE with a reason', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['finance.reports.view']));

    $cat = collect($this->getJson('/api/v1/finance/reports', tenantHeader($t))->assertOk()->json('data'))->keyBy('code');
    expect($cat)->toHaveCount(20)
        ->and($cat['FR-20']['status'])->toBe('NOT_AVAILABLE')->and($cat['FR-20']['reason'])->toContain('actuarial')
        ->and($cat['FR-13']['status'])->toBe('AVAILABLE');

    $na = $this->getJson('/api/v1/finance/reports/FR-20', tenantHeader($t))->assertOk()->json('data');
    expect($na['status'])->toBe('NOT_AVAILABLE')->and($na['rows'])->toBe([]);
    $this->getJson('/api/v1/finance/reports/FR-99', tenantHeader($t))->assertNotFound();
});

it('REQ-ACC-005 runs every available report without error, tenant-scoped, as JSON and CSV', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, ['finance.reports.view']);
    b1010Seed($t, $u);
    b1010Obligation(makeAuthTestTenant('other'), 'RECEIVABLE', 99999, now()->subDays(10));
    Passport::actingAs($u);

    foreach (array_keys(FinanceReportRegistry::definitions()) as $code) {
        $this->getJson("/api/v1/finance/reports/{$code}?from=2020-01-01&to=2030-12-31&currency=XAF", tenantHeader($t))->assertOk();
    }

    $aging = $this->getJson('/api/v1/finance/reports/fr-02', tenantHeader($t))->assertOk()->json('data');
    expect($aging['rows'])->toBe([['currency' => 'XAF', 'bucket_current_minor' => 7000, 'bucket_1_30_minor' => 10000, 'bucket_31_60_minor' => 20000,
        'bucket_61_90_minor' => 0, 'bucket_90_plus_minor' => 0, 'total_minor' => 37000]]);

    $premium = $this->getJson('/api/v1/finance/reports/FR-04', tenantHeader($t))->assertOk()->json('data');
    expect($premium['rows'])->toHaveCount(3)->and(collect($premium['rows'])->sum('outstanding_minor'))->toBe(37000);

    $csv = $this->get('/api/v1/finance/reports/FR-12?format=csv', tenantHeader($t))->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv');
    $lines = array_filter(explode("\n", $csv->getContent()));
    expect($lines)->toHaveCount(2)->and($lines[0])->toContain('variance_minor')->and($lines[1])->toContain('-500');
});

it('grants the Batch 9 / 10-10 finance permissions to the roles each suggests, keeping checkers off makers', function () {
    $codes = array_keys(config('permissions.finance_money_chain'));
    expect($codes)->toContain('finance.obligations.view', 'payments.allocations.reverse', 'cashier.sessions.operate', 'statements.read', 'finance.exceptions.view', 'finance.reports.view')
        ->and($codes)->toHaveCount(24);
    foreach (config('permissions.finance_money_chain') as $code => $meta) {
        foreach ($meta['suggested_roles'] as $role) {
            expect(RoleCatalogue::codes())->toContain($role);
            $perms = RoleCatalogue::defaultPermissions($role);
            expect(in_array('*', $perms, true) || in_array($code, $perms, true))->toBeTrue("{$role} should hold {$code}");
        }
    }

    $checkers = ['finance.obligations.manage', 'payments.allocations.reverse', 'finance.allocation_rules.manage', 'premium_components.close', 'refund.reconcile', 'clearing.reconcile', 'cashier.sessions.approve', 'fx.rates.manage'];
    foreach (['FINANCE_OFFICER', 'CARRIER_STAFF', 'CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_STAFF', 'CUSTOMER_SERVICE', 'AGENT', 'CUSTOMER'] as $maker) {
        expect(array_intersect($checkers, RoleCatalogue::defaultPermissions($maker)))->toBe([], "{$maker} must not hold checker permissions");
    }
    expect(RoleCatalogue::defaultPermissions('BRANCH_MANAGER'))->toContain('cashier.sessions.operate', 'cashier.sessions.approve')
        ->and(RoleCatalogue::defaultPermissions('BROKER_ADMIN'))->toContain('statements.read')
        ->and(RoleCatalogue::defaultPermissions('CARRIER_ADMIN'))->toContain('statements.read')
        ->and(RoleCatalogue::defaultPermissions('CUSTOMER'))->not->toContain('premium_status.read');
});
