<?php

declare(strict_types=1);

use App\Application\Finance\Cashier\CashierSessionService;
use App\Application\Finance\Clearing\ClearingService;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use App\Models\FinancialPostingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b106Lines(string $journalId): array
{
    return DB::table('journal_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.account_id')->where('journal_id', $journalId)
        ->orderByDesc('debit_minor')->get(['ledger_accounts.code', 'ledger_accounts.tenant_id', 'debit_minor', 'credit_minor', 'dimensions'])->all();
}

it('REQ-ACC-001 catalogues every required business event with an active default mapping', function () {
    foreach (['payment.succeeded', 'payment.reconciled', 'refund.approved', 'refund.paid', 'cashier.collection.recorded', 'payment.clearing.settled', 'finance.obligation.created',
        'commission.accrued', 'commission.earned', 'commission.clawed_back', 'commission.paid', 'settlement.approved', 'settlement.settled', 'reinsurance.policy.ceded', 'coinsurance.apportioned'] as $e) {
        expect(DB::table('accounting_events')->where('code', $e)->exists())->toBeTrue($e);
        $m = DB::table('accounting_event_mappings')->whereNull('tenant_id')->where('event_code', $e)->where('status', 'ACTIVE')->sole();
        expect(DefaultChartOfAccounts::ACCOUNTS)->toHaveKeys([$m->debit_account_code, $m->credit_account_code]);
    }
});

it('REQ-ACC-001 posts through the default mapping into the tenant chart, idempotent per (event, reference)', function () {
    $t = makeAuthTestTenant();
    $ref = (string) Str::uuid();
    $svc = app(FinancialPostingService::class);

    $j = $svc->post($t->id, 'finance.obligation.created', $ref, 50000, 'XAF', 'corr-1');
    expect($svc->post($t->id, 'finance.obligation.created', $ref, 50000, 'XAF', 'corr-2'))->toBe($j);
    expect(DB::table('journals')->where('reference_id', $ref)->count())->toBe(1);

    $lines = b106Lines($j);
    expect($lines[0]->code)->toBe('411000')->and((int) $lines[0]->debit_minor)->toBe(50000)->and($lines[0]->tenant_id)->toBe($t->id);
    expect($lines[1]->code)->toBe('702000')->and((int) $lines[1]->credit_minor)->toBe(50000);
    expect(json_decode($lines[0]->dimensions, true))->toMatchArray(['source' => 'event_mapping', 'mapping_version' => 1]);
});

it('REQ-ACC-001 versions a tenant mapping that overrides the default only for that tenant', function () {
    $t = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    $maps = app(AccountingEventMappingService::class);

    $v2 = $maps->publish($t->id, 'refund.paid', '419000', '585000', null, 'Refunds paid by mobile money');
    $v3 = $maps->publish($t->id, 'refund.paid', '419000', '571000', null, 'Cash refunds');
    expect((int) $v2->version)->toBe(1)->and((int) $v3->version)->toBe(2);
    expect(DB::table('accounting_event_mappings')->where('id', $v2->id)->value('status'))->toBe('SUPERSEDED');

    $j = app(FinancialPostingService::class)->post($t->id, 'refund.paid', (string) Str::uuid(), 1000, 'XAF', 'c');
    expect(b106Lines($j)[1]->code)->toBe('571000');
    expect(json_decode(b106Lines($j)[0]->dimensions, true)['mapping_version'])->toBe(2);
    $j2 = app(FinancialPostingService::class)->post($other->id, 'refund.paid', (string) Str::uuid(), 1000, 'XAF', 'c');
    expect(b106Lines($j2)[1]->code)->toBe('521000');

    expect(fn () => $maps->publish($t->id, 'not.an.event', '419000', '571000', null, 'x'))->toThrow(ValidationException::class);
    expect(fn () => $maps->publish($t->id, 'refund.paid', '419000', '419000', null, 'x'))->toThrow(ValidationException::class);
});

it('REQ-ACC-001 keeps an approved posting profile as an explicit override and refuses unmapped events', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, []);
    $checker = makeAuthTestUser($t, []);
    $acc = fn (string $code) => (string) tap((string) Str::uuid(), fn ($id) => DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => $code, 'name' => $code, 'type' => 'ASSET', 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]));
    $d = $acc('X-DR');
    $c = $acc('X-CR');
    FinancialPostingProfile::create(['tenant_id' => $t->id, 'event_type' => 'payment.succeeded', 'currency' => 'XAF', 'debit_account_id' => $d, 'credit_account_id' => $c, 'status' => 'APPROVED', 'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()]);

    $j = app(FinancialPostingService::class)->post($t->id, 'payment.succeeded', (string) Str::uuid(), 700, 'XAF', 'c');
    expect(b106Lines($j)[0]->code)->toBe('X-DR');

    expect(fn () => app(FinancialPostingService::class)->post($t->id, 'unknown.event', (string) Str::uuid(), 700, 'XAF', 'c'))->toThrow(ValidationException::class);
});

it('REQ-ACC-001 posts cashier collections and clearing settlements at their call sites', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, []);
    $branch = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $t->id, 'code' => 'BR-'.Str::random(5), 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()]);
    $cashier = app(CashierSessionService::class);
    $s = $cashier->open($t->id, $branch, 0, 'XAF', $u);
    $col = $cashier->collect($t->id, $s->id, ['method' => 'CASH', 'amount_minor' => 25000, 'payer_name' => 'Jean'], $u);
    $j = DB::table('journals')->where(['reference_type' => 'cashier.collection.recorded', 'reference_id' => $col->id])->sole();
    expect(array_column(b106Lines($j->id), 'code'))->toBe(['571000', '411000']);

    $clearing = app(ClearingService::class);
    $batch = $clearing->open($t->id, ['provider' => 'fake', 'settlement_reference' => 'S-1', 'settlement_date' => now()->toDateString(), 'currency' => 'XAF'], $u);
    $batch->update(['expected_minor' => 10000]);
    $clearing->settle($batch, ['settled_minor' => 9800, 'fee_minor' => 200, 'bank_reference' => 'BNK-1'], $u);
    $j = DB::table('journals')->where(['reference_type' => 'payment.clearing.settled', 'reference_id' => $batch->id])->sole();
    expect(array_column(b106Lines($j->id), 'code'))->toBe(['521000', '585000'])->and((int) b106Lines($j->id)[0]->debit_minor)->toBe(9800);
});
