<?php

declare(strict_types=1);

/*
 * D10 — owner decisions (2026-09-25):
 *  1. POST /api/v1/ledger/journals is a deprecated alias that creates a DRAFT manual journal (maker-checker).
 *  2. The weekly POLICY-basis settlements:prepare schedule is retired; the command refuses OBLIGATIONS overlap.
 *  3. CASHIER role; BRANCH_MANAGER approves but no longer operates the till.
 *  4. docs/spec/FINANCE_REPORTS_V1.md documents FR-01..FR-20.
 *  5. Commission reopen PAID -> PAYABLE on payout reversal.
 *  6. Batch 10 permission grants.
 */

use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\FinancialDistribution\PartnerStatementService;
use App\Application\FinancialDistribution\PayoutService;
use App\Application\Commissions\Statements\CommissionStatementService;
use App\Application\Identity\RoleCatalogue;
use App\Application\Settlements\SettlementService;
use App\Models\CommissionAccrual;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(fn () => class_exists(\App\Domain\Ledger\Journal::class));

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function d10Account(Tenant $t, string $code): string
{
    $id = (string) Str::uuid();
    DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => $code, 'name' => 'Acc '.$code, 'type' => 'ASSET', 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('D10-1 the legacy POST ledger/journals creates a DRAFT (no posting) that must pass validate/approve/post', function () {
    $t = makeAuthTestTenant();
    $perms = ['ledger.read', 'ledger.adjust', 'ledger.approve', 'ledger.post'];
    $maker = makeAuthTestUser($t, $perms);
    $checker = makeAuthTestUser($t, $perms);
    $a = d10Account($t, '1000');
    $b = d10Account($t, '2000');

    Passport::actingAs($maker);
    $res = $this->postJson('/api/v1/ledger/journals', ['reference_type' => 'MANUAL_ADJUSTMENT', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'CORRECTION',
        'lines' => [['account_id' => $a, 'debit_minor' => 700], ['account_id' => $b, 'credit_minor' => 700]]], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('meta.deprecated', true)->assertHeader('Deprecation', 'true');
    $id = $res->json('data.id');
    $j = DB::table('journals')->find($id);
    expect($j->status)->toBe('DRAFT')->and($j->journal_type)->toBe('MANUAL')->and($j->posted_at)->toBeNull()->and($j->created_by)->toBe($maker->id);

    $this->postJson("/api/v1/ledger/manual-journals/{$id}/validate", [], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/approve", [], tenantHeader($t))->assertForbidden();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/approve", [], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/post", [], tenantHeader($t))->assertOk()->assertJsonPath('data.journal.status', 'POSTED');
});

it('D10-2 no longer schedules settlements:prepare and refuses policies already in an OBLIGATIONS batch', function () {
    $events = app(Schedule::class)->events();
    expect(collect($events)->contains(fn ($e) => str_contains($e->command ?? '', 'settlements:prepare')))->toBeFalse();

    $f = makeMobileCustomerFixture('+237671119004');
    $admin = makeMobileTenantStaffUser($f['tenant'], '+237671119099', 'PLATFORM_ADMIN');
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant']);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-D10', 'payment_intent_id' => $payment->id, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subWeek()->startOfWeek()->addDay()]);

    $ob = app(SettlementService::class)->draft(['tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'currency' => 'XAF',
        'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'idempotency_key' => 'd10-ob'], $admin);
    DB::table('settlement_items')->insert(['id' => (string) Str::uuid(), 'settlement_batch_id' => $ob->id, 'policy_id' => $policy->id, 'payment_intent_id' => $payment->id,
        'gross_premium_minor' => 100000, 'commission_minor' => 0, 'tax_minor' => 0, 'adjustment_minor' => 0, 'net_due_minor' => 100000, 'currency' => 'XAF', 'status' => 'INCLUDED', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('settlements:prepare')->expectsOutputToContain('refused')->assertSuccessful();
    expect(DB::table('settlement_batches')->where('carrier_id', $f['carrier']->id)->where('calculation_basis', '!=', 'OBLIGATIONS')->count())->toBe(0);
});

it('D10-3 adds a CASHIER tenant role distinct from the approving BRANCH_MANAGER', function () {
    expect(RoleCatalogue::codes())->toContain('CASHIER')
        ->and(RoleCatalogue::invitableOptions())->toHaveKey('CASHIER')
        ->and(RoleCatalogue::isPlatformOnly('CASHIER'))->toBeFalse()
        ->and(RoleCatalogue::defaultPermissions('CASHIER'))->toEqualCanonicalizing(['cashier.sessions.view', 'cashier.sessions.operate', 'fx.rates.view', 'premium_status.read', 'statements.read', 'finance.obligations.view'])
        ->and(RoleCatalogue::defaultPermissions('BRANCH_MANAGER'))->toContain('cashier.sessions.approve')->not->toContain('cashier.sessions.operate');
});

it('D10-4 documents every finance report in FINANCE_REPORTS_V1.md', function () {
    $doc = file_get_contents(base_path('docs/spec/FINANCE_REPORTS_V1.md'));
    foreach (FinanceReportRegistry::definitions() as $code => $def) {
        expect($doc)->toContain("| {$code} | {$def['title']} |")->toContain($def['screen']);
    }
    expect(FinanceReportRegistry::definitions())->toHaveCount(20);
});

it('D10-5 reopens PAID accruals to PAYABLE when their payout is reversed (maker-checker), restoring amounts', function () {
    expect(CommissionMachine::definition()->toArray()['states']['PAID']['terminal'] ?? false)->toBeFalse();

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDays(5), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subDays(5)]);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'D10 Broker', 'status' => 'ACTIVE']);
    $partner = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $accrual = CommissionAccrual::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'partner_id' => $partner->id, 'rule_version' => 'v1', 'amount_minor' => 10000,
        'currency' => 'XAF', 'status' => 'VESTED', 'vested_minor' => 10000, 'clawed_back_minor' => 0, 'available_at' => now()]);
    $maker = makeAuthTestUser($f['tenant'], ['statements.prepare', 'payout.request', 'payout.reverse'], 'D10_MAKER');
    $checker = makeAuthTestUser($f['tenant'], ['statements.approve', 'statements.publish', 'payout.approve', 'payout.process', 'payout.reverse'], 'D10_CHECKER');

    $s = app(CommissionStatementService::class)->generate($f['tenant']->id, $partner->id, now()->subMonth()->toDateString(), now()->addDay()->toDateString(), 'XAF', $maker);
    $s = app(PartnerStatementService::class)->publish(app(PartnerStatementService::class)->approve($s, $checker), $checker);
    $pay = app(PayoutService::class);
    $p = $pay->request($s, ['amount_minor' => 10000, 'destination_type' => 'BANK', 'destination' => 'CM00-TEST', 'idempotency_key' => 'po-d10'], $maker);
    $p = $pay->complete($pay->markProcessing($pay->approve($p, $checker), ['provider' => 'BANK'], $checker), ['provider_reference' => 'ref-d10'], $checker);
    expect($accrual->fresh())->status->toBe('PAID')->and((int) $accrual->fresh()->paid_minor)->toBe(10000);

    // maker-checker: the requester cannot reverse their own payout
    expect(fn () => $pay->reverse($p, 'BANK_RETURN', $maker))->toThrow(ValidationException::class);
    expect($accrual->fresh()->status)->toBe('PAID');

    $pay->reverse($p, 'BANK_RETURN', $checker);
    $a = $accrual->fresh();
    expect($a->status)->toBe('VESTED')->and(CommissionMachine::blueprintState($a->status))->toBe('PAYABLE')
        ->and((int) $a->paid_minor)->toBe(0)->and($a->paid_at)->toBeNull()
        ->and($p->fresh()->status)->toBe('REVERSED');
    expect(DB::table('workflow_transition_history')->where('subject_id', $a->id)->where('event', 'reopen')->where('to_state', 'VESTED')->exists())->toBeTrue();
});

it('D10-6 catalogues the Batch 10 permissions and grants each to its suggested roles, keeping checkers off makers', function () {
    $expected = ['ledger.periods.close', 'ledger.periods.reopen', 'ledger.approve', 'ledger.post', 'technical_accounting.read', 'technical_accounting.actuarial.import',
        'technical_accounting.actuarial.approve', 'technical_accounting.upr.post', 'commission.statements.adjust', 'commission.statements.adjustments.approve',
        'commission.statements.dispute', 'commission.statements.dispute.resolve', 'settlement.reconcile', 'bordereaux.view'];
    expect(array_keys(config('permissions.batch10_finance')))->toEqualCanonicalizing($expected);
    foreach (config('permissions.batch10_finance') as $code => $meta) {
        foreach ($meta['suggested_roles'] as $role) {
            expect(RoleCatalogue::codes())->toContain($role);
            $perms = RoleCatalogue::defaultPermissions($role);
            expect(in_array('*', $perms, true) || in_array($code, $perms, true))->toBeTrue("{$role} should hold {$code}");
        }
    }
    $checkers = ['ledger.periods.reopen', 'ledger.approve', 'ledger.post', 'technical_accounting.actuarial.approve', 'technical_accounting.upr.post',
        'commission.statements.adjustments.approve', 'commission.statements.dispute.resolve', 'settlement.reconcile'];
    foreach (['FINANCE_OFFICER', 'CARRIER_STAFF', 'CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_STAFF', 'REINSURANCE_OFFICER', 'CASHIER', 'BRANCH_MANAGER', 'AGENT', 'CUSTOMER'] as $maker) {
        expect(array_intersect($checkers, RoleCatalogue::defaultPermissions($maker)))->toBe([], "{$maker} must not hold checker permissions");
    }
    expect(RoleCatalogue::defaultPermissions('CARRIER_SUPER_ADMIN'))->toContain(...$checkers)
        ->and(RoleCatalogue::defaultPermissions('FINANCE_OFFICER'))->toContain('ledger.periods.close', 'technical_accounting.actuarial.import', 'commission.statements.adjust', 'bordereaux.view')
        ->and(RoleCatalogue::defaultPermissions('BROKER_ADMIN'))->toContain('commission.statements.dispute', 'bordereaux.view')
        ->and(RoleCatalogue::defaultPermissions('CARRIER_STAFF'))->toContain('bordereaux.view')
        ->and(RoleCatalogue::defaultPermissions('REINSURANCE_OFFICER'))->toContain('technical_accounting.read');
});
