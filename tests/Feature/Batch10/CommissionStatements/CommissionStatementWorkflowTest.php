<?php

declare(strict_types=1);

/*
 * Batch 10-3 — REQ-COM-003 (WRS WF-066/067; FRP VI): commission statements generated per partner per
 * period from accruals, ADJUSTMENT lines under maker-checker, disputes through the case engine, and a payout
 * that settles the PAYABLE/COMMISSION obligation and posts commission.paid.
 */

use App\Application\Cases\Models\WorkCase;
use App\Application\Commissions\Statements\CommissionStatementService;
use App\Application\Finance\Statements\AccountStatementService;
use App\Application\FinancialDistribution\PartnerStatementService;
use App\Application\FinancialDistribution\PayoutService;
use App\Models\CommissionAccrual;
use App\Models\FinancialPostingProfile;
use App\Models\Partner;
use App\Models\PartnerStatement;
use App\Models\Party;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

// App\Domain\Ledger\JournalLine is declared inside Journal.php (no PSR-4 file of its own), so LedgerService::post
// only works once Journal is loaded. Pre-existing; owned by the ledger agents — preload here rather than touch it.
beforeEach(fn () => class_exists(\App\Domain\Ledger\Journal::class));

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b103World(bool $profile = true): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDays(5), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subDays(5),
    ]);
    $partners = [];
    foreach (['B103 Broker A', 'B103 Broker B'] as $i => $name) {
        $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE']);
        $partners[$i] = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
        CommissionAccrual::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'partner_id' => $partners[$i]->id, 'rule_version' => 'v1', 'amount_minor' => 10000 * ($i + 1),
            'currency' => 'XAF', 'status' => 'VESTED', 'vested_minor' => 10000 * ($i + 1), 'clawed_back_minor' => 0, 'available_at' => now()]);
    }
    $maker = makeAuthTestUser($f['tenant'], ['statements.prepare', 'commission.statements.adjust', 'payout.request', 'commission.statements.dispute'], 'B103_MAKER');
    $checker = makeAuthTestUser($f['tenant'], ['statements.approve', 'statements.publish', 'commission.statements.adjustments.approve', 'payout.approve', 'payout.process', 'payout.reverse', 'commission.statements.dispute.resolve'], 'B103_CHECKER');
    if ($profile) {
        $acc = fn (string $code, string $type) => DB::table('ledger_accounts')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'code' => $code, 'name' => $code, 'type' => $type, 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()], 'id');
        FinancialPostingProfile::create(['tenant_id' => $f['tenant']->id, 'event_type' => 'commission.paid', 'currency' => 'XAF', 'debit_account_id' => $acc('COMM_PAYABLE', 'LIABILITY'),
            'credit_account_id' => $acc('BANK', 'ASSET'), 'status' => 'APPROVED', 'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()]);
    }

    return $f + ['policy' => $policy, 'partners' => $partners, 'maker' => $maker, 'checker' => $checker,
        'start' => now()->subDays(10)->toDateString(), 'end' => now()->toDateString()];
}

function b103Paid(array $w, PartnerStatement $s, int $amount): \App\Models\PartnerPayoutRequest
{
    $pay = app(PayoutService::class);
    $p = $pay->request($s, ['amount_minor' => $amount, 'destination_type' => 'BANK', 'destination' => 'CM00-TEST', 'idempotency_key' => 'po-'.Str::random(8)], $w['maker']);
    $p = $pay->approve($p, $w['checker']);
    $p = $pay->markProcessing($p, ['provider' => 'BANK'], $w['checker']);

    return $pay->complete($p, ['provider_reference' => 'ref-'.Str::random(8)], $w['checker']);
}

it('generates one statement per partner per period from accruals, idempotently', function () {
    $w = b103World();
    $svc = app(CommissionStatementService::class);
    $all = $svc->generatePeriod($w['tenant']->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $again = $svc->generatePeriod($w['tenant']->id, $w['start'], $w['end'], 'XAF', $w['maker']);

    expect($all)->toHaveCount(2)
        ->and(collect($all)->pluck('closing_balance_minor')->sort()->values()->all())->toBe([10000, 20000])
        ->and(collect($again)->pluck('id')->all())->toBe(collect($all)->pluck('id')->all())
        ->and(PartnerStatement::where('tenant_id', $w['tenant']->id)->count())->toBe(2);
});

it('applies adjustments only after a different checker approves them, and blocks approval while one is pending', function () {
    $w = b103World();
    $svc = app(CommissionStatementService::class);
    $s = $svc->generate($w['tenant']->id, $w['partners'][0]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $adj = $svc->proposeAdjustment($s, 2500, 'Volume bonus', $w['maker']);

    expect($s->refresh()->closing_balance_minor)->toBe(10000)
        ->and(fn () => $svc->approveAdjustment($adj, $w['maker']))->toThrow(ValidationException::class)
        ->and(fn () => app(PartnerStatementService::class)->approve($s, $w['checker']))->toThrow(ValidationException::class);

    $svc->approveAdjustment($adj, $w['checker']);
    $rejected = $svc->rejectAdjustment($svc->proposeAdjustment($s, -99999, 'Typo', $w['maker']), $w['checker'], 'Wrong amount');
    $s->refresh();

    expect($s->closing_balance_minor)->toBe(12500)->and($s->adjustments_minor)->toBe(2500)->and($rejected->adjustment_status)->toBe('REJECTED')
        ->and(fn () => $svc->approveAdjustment($svc->proposeAdjustment($s, -50000, 'Too big', $w['maker']), $w['checker']))->toThrow(ValidationException::class);

    // the statement render only carries approved adjustment lines
    DB::table('partner_statement_items')->where('partner_statement_id', $s->id)->where('adjustment_status', 'PROPOSED')->update(['adjustment_status' => 'REJECTED', 'decided_by' => $w['checker']->id]);
    $render = app(AccountStatementService::class)->fromPartnerStatement($s->refresh());
    expect(collect($render['lines'])->where('line_type', 'ADJUSTMENT')->pluck('amount_minor')->all())->toBe([2500])
        ->and($render['totals_by_type']['ADJUSTMENT'])->toBe(2500);

    // the database also enforces maker <> checker on adjustment lines
    expect(fn () => DB::table('partner_statement_items')->where('id', $adj->id)->update(['decided_by' => $w['maker']->id]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('opens a PAYABLE obligation on approval; the payout settles it and posts commission.paid; reversal unwinds both', function () {
    $w = b103World();
    $s = app(CommissionStatementService::class)->generate($w['tenant']->id, $w['partners'][1]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $s = app(PartnerStatementService::class)->approve($s, $w['checker']);
    $s = app(PartnerStatementService::class)->publish($s, $w['checker']);
    $o = DB::table('financial_obligations')->where('id', $s->obligation_id)->first();

    expect($o->kind)->toBe('PAYABLE')->and($o->type)->toBe('COMMISSION')->and((int) $o->amount_minor)->toBe(20000)->and($o->creditor_id)->toBe($s->partner_id);

    $p = b103Paid($w, $s, 20000);
    $o = DB::table('financial_obligations')->where('id', $s->obligation_id)->first();
    $journal = DB::table('journals')->where('id', $p->journal_id)->first();

    expect($o->status)->toBe('SETTLED')->and((int) $o->outstanding_minor)->toBe(0)
        ->and($journal->reference_type)->toBe('commission.paid')->and($journal->reference_id)->toBe($p->id)
        ->and((int) DB::table('journal_lines')->where('journal_id', $p->journal_id)->sum('debit_minor'))->toBe(20000);

    app(PayoutService::class)->reverse($p, 'BANK_RETURN', $w['checker']);
    $o = DB::table('financial_obligations')->where('id', $s->obligation_id)->first();
    expect($o->status)->toBe('OPEN')->and((int) $o->outstanding_minor)->toBe(20000)
        ->and(DB::table('journals')->where('id', $p->journal_id)->value('status'))->toBe('REVERSED');
});

it('completes a payout without a posting profile through the default GL mapping (Batch 10-6)', function () {
    $w = b103World(false);
    $s = app(CommissionStatementService::class)->generate($w['tenant']->id, $w['partners'][0]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $s = app(PartnerStatementService::class)->publish(app(PartnerStatementService::class)->approve($s, $w['checker']), $w['checker']);

    $p = b103Paid($w, $s, 10000);
    expect($p->journal_id)->not->toBeNull()
        ->and(DB::table('journals')->where('id', $p->journal_id)->value('status'))->toBe('POSTED')
        ->and(DB::table('financial_obligations')->where('id', $s->obligation_id)->value('status'))->toBeIn(['PARTIALLY_SETTLED', 'SETTLED']);
});

it('disputes a published statement through a case, then re-approval after resolution opens a fresh payable', function () {
    $w = b103World();
    $svc = app(CommissionStatementService::class);
    $s = $svc->generate($w['tenant']->id, $w['partners'][0]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $s = app(PartnerStatementService::class)->publish(app(PartnerStatementService::class)->approve($s, $w['checker']), $w['checker']);
    $first = $s->obligation_id;

    $s = $svc->dispute($s, 'Missing policy commission', $w['maker']);
    $case = WorkCase::withoutGlobalScopes()->find($s->dispute_case_id);
    expect($s->status)->toBe('DISPUTED')->and($case->case_type_code)->toBe('COMMISSION_DISPUTE')->and($case->subject_id)->toBe($s->id)
        ->and(DB::table('financial_obligations')->where('id', $first)->value('status'))->toBe('CANCELLED');

    $svc->approveAdjustment($svc->proposeAdjustment($s, 1500, 'Missing policy', $w['maker']), $w['checker']);
    $s = $svc->resolveDispute($s, 'Adjusted +1500', $w['checker']);
    expect($s->status)->toBe('DRAFT')->and($s->revision)->toBe(2)->and($s->approved_by)->toBeNull()
        ->and(WorkCase::withoutGlobalScopes()->find($s->dispute_case_id)->status)->toBe('CLOSED');

    $s = app(PartnerStatementService::class)->approve($s, $w['checker']);
    expect($s->obligation_id)->not->toBe($first)
        ->and((int) DB::table('financial_obligations')->where('id', $s->obligation_id)->value('amount_minor'))->toBe(11500);
});

it('will not dispute a statement with a live or paid payout', function () {
    $w = b103World();
    $s = app(CommissionStatementService::class)->generate($w['tenant']->id, $w['partners'][0]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $s = app(PartnerStatementService::class)->publish(app(PartnerStatementService::class)->approve($s, $w['checker']), $w['checker']);
    b103Paid($w, $s, 4000);

    expect(fn () => app(CommissionStatementService::class)->dispute($s, 'late', $w['maker']))->toThrow(ValidationException::class);
});

it('exposes adjustments over HTTP with permissions and tenant scoping', function () {
    $w = b103World();
    $s = app(CommissionStatementService::class)->generate($w['tenant']->id, $w['partners'][0]->id, $w['start'], $w['end'], 'XAF', $w['maker']);
    $h = tenantHeader($w['tenant']);

    Passport::actingAs($w['maker']);
    $item = $this->postJson("/api/v1/partner-statements/{$s->id}/adjustments", ['amount_minor' => 700, 'reason' => 'Bonus'], $h)->assertCreated()->json('id');
    $this->postJson("/api/v1/partner-statement-adjustments/{$item}/approve", [], $h)->assertForbidden();

    Passport::actingAs($w['checker']);
    $this->postJson("/api/v1/partner-statement-adjustments/{$item}/approve", [], $h)->assertOk()->assertJsonPath('adjustment_status', 'APPROVED');
    $this->postJson('/api/v1/commission-statements/generate', ['period_start' => $w['start'], 'period_end' => $w['end'], 'currency' => 'XAF'], $h)->assertForbidden();

    $other = makeAuthTestTenant();
    $stranger = makeAuthTestUser($other, ['commission.statements.adjust'], 'B103_OTHER');
    Passport::actingAs($stranger);
    $this->postJson("/api/v1/partner-statements/{$s->id}/adjustments", ['amount_minor' => 1, 'reason' => 'x'], tenantHeader($other))->assertNotFound();
    expect($s->refresh()->closing_balance_minor)->toBe(10700);
});
