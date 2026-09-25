<?php

declare(strict_types=1);

// Agent F1 — finance sub-ledger: immutable entries projected from posted journals, dimensions, reversal, idempotency, periods, maker-checker.

use App\Application\Finance\Subledger\SubledgerProjector;
use App\Application\Finance\Subledger\SubledgerQuery;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\Journals\ManualJournalService;
use App\Application\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/subledger_helpers.php';

it('projects every posted journal line into one sub-ledger entry with the spec dimensions and sub-ledger type', function () {
    $w = fslWorld();
    $n = app(SubledgerProjector::class)->catchUp($w['tenant']->id);
    expect($n)->toBe(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.tenant_id', $w['tenant']->id)->count());
    expect(app(SubledgerProjector::class)->catchUp($w['tenant']->id))->toBe(0); // idempotent

    $billed = DB::table('finance_ledger_entries')->where('tenant_id', $w['tenant']->id)->where('journal_type', 'PREMIUM_BILLING')->where('debit_amount', '>', 0)->first();
    expect($billed->entry_type)->toBe('CUSTOMER_RECEIVABLE')
        ->and($billed->policy_id)->toBe($w['policy']->id)->and($billed->customer_id)->toBe($w['party']->id)->and($billed->insurer_id)->toBe($w['carrier']->id)
        ->and($billed->broker_id)->toBe($w['broker']->id)->and($billed->product_id)->toBe($w['product']->id)->and($billed->insurance_class_id)->toBe('AUTO')
        ->and($billed->source_entity_type)->toBe('financial_obligations')->and($billed->idempotency_key)->toStartWith('jl:');
    $comm = DB::table('finance_ledger_entries')->where('commission_id', $w['accrual']->id)->where('credit_amount', '>', 0)->first();
    expect($comm->entry_type)->toBe('AGENT_COMMISSION_PAYABLE')->and($comm->agent_id)->toBe($w['agent']->id)->and($comm->journal_type)->toBe('COMMISSION_ACCRUAL');
    expect(DB::table('finance_ledger_entries')->where('journal_type', 'PREMIUM_COLLECTION')->where('credit_amount', 60000)->value('payment_id'))->toBe($w['payment']->id);
});

it('keeps posted entries immutable and one-sided, and a reversal preserves the original entry', function () {
    $w = fslWorld();
    app(SubledgerProjector::class)->catchUp($w['tenant']->id);
    $e = DB::table('finance_ledger_entries')->where('tenant_id', $w['tenant']->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('finance_ledger_entries')->where('id', $e->id)->update(['debit_amount' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('finance_ledger_entries')->where('id', $e->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('finance_ledger_entries')->insert(array_merge((array) $e, ['id' => (string) Str::uuid(), 'journal_line_id' => (string) Str::uuid(),
        'idempotency_key' => 'x', 'debit_amount' => 5, 'credit_amount' => 5]))))->toThrow(QueryException::class);

    $journal = DB::table('journals')->where('reference_type', 'settlement.approved')->where('tenant_id', $w['tenant']->id)->value('id');
    $original = DB::table('finance_ledger_entries')->where('journal_id', $journal)->orderBy('id')->get();
    $mirror = app(LedgerService::class)->reverse($journal, 'test-reversal');
    app(SubledgerProjector::class)->catchUp($w['tenant']->id);
    expect(DB::table('finance_ledger_entries')->where('journal_id', $journal)->orderBy('id')->get()->toArray())->toEqual($original->toArray());
    $rev = DB::table('finance_ledger_entries')->where('journal_id', $mirror)->get();
    expect($rev)->toHaveCount(2)->and($rev->pluck('reverses_entry_id')->filter()->sort()->values()->all())->toEqual($original->pluck('id')->sort()->values()->all())
        ->and($rev->first()->journal_type)->toBe('PAYMENT_REVERSAL')->and($rev->first()->insurer_id)->toBe($w['carrier']->id);
    // Balanced: the reversal nets the original to zero on the insurer payable sub-ledger.
    $t = app(SubledgerQuery::class)->totals($w['tenant']->id, ['settlement_id' => DB::table('journals')->where('id', $journal)->value('reference_id')]);
    expect($t['XAF']['net_minor'])->toBe(0);
});

it('does not duplicate a posting for a duplicate idempotency key and rejects cross-tenant reads', function () {
    $w = fslWorld();
    $before = DB::table('journals')->count();
    $again = app(FinancialPostingService::class)->post($w['tenant']->id, 'commission.accrued', $w['accrual']->id, 10000, 'XAF', 'dup');
    expect(DB::table('journals')->count())->toBe($before)->and($again)->toBe(DB::table('journals')->where('reference_type', 'commission.accrued')->where('reference_id', $w['accrual']->id)->value('id'));

    $other = fslWorld();
    app(SubledgerProjector::class)->catchUp();
    expect(app(SubledgerQuery::class)->entries($w['tenant']->id, [])->pluck('e.tenant_id')->unique()->all())->toBe([$w['tenant']->id]);
    Passport::actingAs($w['staff']);
    $foreign = DB::table('finance_ledger_entries')->where('tenant_id', $other['tenant']->id)->value('id');
    $this->getJson('/api/v1/finance/subledger/entries/'.$foreign.'/drilldown', tenantHeaderFor($w['tenant']))->assertNotFound();
    $this->getJson('/api/v1/finance/subledger/entries', tenantHeaderFor($other['tenant']))->assertForbidden();
});

it('manual adjustment requires maker-checker and a reason, and a closed period rejects ordinary backdated posting', function () {
    $w = fslWorld();
    $svc = app(ManualJournalService::class);
    $acc = fn (string $code) => DB::table('ledger_accounts')->where('tenant_id', $w['tenant']->id)->where('code', $code)->value('id');
    $lines = [['account_id' => $acc('471000'), 'debit_minor' => 500], ['account_id' => $acc('411000'), 'credit_minor' => 500]];
    $maker = makeAuthTestUser($w['tenant'], []);
    expect(fn () => $svc->createDraft($w['tenant']->id, $maker->id, ['reference_type' => 'MANUAL', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'lines' => $lines], 'c'))
        ->toThrow(ErrorException::class); // reason_code is mandatory
    $id = $svc->createDraft($w['tenant']->id, $maker->id, ['reference_type' => 'MANUAL', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'WRITE_BACK', 'lines' => $lines], 'c');
    $svc->validate($w['tenant']->id, $id, $maker->id);
    expect(fn () => $svc->approve($w['tenant']->id, $id, $maker->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $svc->approve($w['tenant']->id, $id, $w['staff']->id);
    $svc->post($w['tenant']->id, $id, $w['staff']->id);
    app(SubledgerProjector::class)->catchUp($w['tenant']->id);
    expect(DB::table('finance_ledger_entries')->where('journal_id', $id)->value('journal_type'))->toBe('MANUAL_ADJUSTMENT');

    DB::table('accounting_periods')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $w['tenant']->id, 'fiscal_year' => 2020, 'period_number' => 1, 'starts_on' => '2020-01-01',
        'ends_on' => '2020-01-31', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
    $back = $svc->createDraft($w['tenant']->id, $maker->id, ['reference_type' => 'MANUAL', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'BACKDATED',
        'journal_date' => '2020-01-15', 'lines' => $lines], 'c');
    expect(fn () => $svc->validate($w['tenant']->id, $back, $maker->id))->toThrow(ValidationException::class);
});
