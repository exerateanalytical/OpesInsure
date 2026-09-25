<?php

declare(strict_types=1);

// Agent F1 — counterparty accounts, premium remittance / unapplied cash, commission spec lifecycle and clawback.

use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\Finance\Subledger\CounterpartyAccountService;
use App\Application\Finance\Subledger\PremiumRemittanceService;
use App\Application\Finance\Subledger\SubledgerCatalogue;
use App\Application\Finance\Subledger\SubledgerViews;
use App\Models\CommissionAccrual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/subledger_helpers.php';

it('opens every account type of a relationship linked to its GL control account, with maker-checker approval and a balance derived from entries', function () {
    $w = fslWorld();
    $svc = app(CounterpartyAccountService::class);
    $accounts = $svc->openRelationship($w['tenant']->id, 'BROKER_TO_INSURER', $w['carrier']->id, 'XAF', $w['staff']->id);
    expect(array_column($accounts, 'account_type'))->toBe(SubledgerCatalogue::RELATIONSHIPS['BROKER_TO_INSURER']);
    foreach ($accounts as $a) {
        $code = DB::table('ledger_accounts')->where('id', $a->gl_control_account_id)->value('code');
        expect($code)->toBe(SubledgerCatalogue::CONTROL_ACCOUNTS['BROKER_TO_INSURER'][$a->account_type])->and($a->status)->toBe('PENDING_APPROVAL');
    }
    $payable = collect($accounts)->firstWhere('account_type', 'PREMIUM_PAYABLE');
    expect(fn () => $svc->approve($w['tenant']->id, $payable->id, $w['staff']->id))->toThrow(ValidationException::class);
    $svc->approve($w['tenant']->id, $payable->id, makeAuthTestUser($w['tenant'], [])->id);
    // A later posting to the insurer premium payable is tagged to the approved account; its balance is Σ entries (credit = owed to the insurer).
    fslCarrierPayable($w['tenant'], $w['policy'], 25000);
    expect($svc->balance($w['tenant']->id, $payable->id))->toMatchArray(['credit_minor' => 90000 + 25000, 'balance_minor' => -115000]);
    expect(Schema::hasColumn('finance_counterparty_accounts', 'balance_minor'))->toBeFalse(); // no stored, editable balance
});

it('partial client payment leaves the correct premium balance and premium status', function () {
    $w = fslWorld();
    $o = DB::table('financial_obligations')->find($w['receivable']->id);
    expect((int) $o->outstanding_minor)->toBe(40000)->and(SubledgerViews::premiumStatus($o))->toBe('PARTIALLY_COLLECTED');
    $account = app(SubledgerViews::class)->customerLedger($w['tenant']->id, $w['party']->id, 'UNPAID_PREMIUMS', ['currency' => 'XAF']);
    expect($account['rows'][0]['outstanding_minor'])->toBe(40000);
});

it('partial broker remittance leaves the correct remittance balance, and unapplied cash stays visible until allocated', function () {
    $w = fslWorld();
    $svc = app(PremiumRemittanceService::class);
    $r = $svc->record($w['tenant']->id, ['insurer_id' => $w['carrier']->id, 'broker_id' => $w['broker']->id, 'currency' => 'XAF', 'amount_minor' => 50000, 'idempotency_key' => 'rem-1'], $w['staff']->id);
    expect($svc->record($w['tenant']->id, ['insurer_id' => $w['carrier']->id, 'currency' => 'XAF', 'amount_minor' => 50000, 'idempotency_key' => 'rem-1'], $w['staff']->id)->id)->toBe($r->id);
    Passport::actingAs($w['staff']);
    $unapplied = fn () => $this->getJson('/api/v1/finance/subledger/reports/UNAPPLIED_CASH?currency=XAF', tenantHeaderFor($w['tenant']))->assertOk()->json('data.rows');
    expect($unapplied())->toHaveCount(1)->and($unapplied()[0]['unapplied_minor'])->toBe(50000);

    expect(fn () => $svc->allocate($w['tenant']->id, $r->id, [['financial_obligation_id' => $w['payable']->id, 'amount_minor' => 60000]], $w['staff']->id))->toThrow(ValidationException::class);
    expect(fn () => $svc->allocate($w['tenant']->id, $r->id, [['financial_obligation_id' => $w['receivable']->id, 'amount_minor' => 100]], $w['staff']->id))->toThrow(ValidationException::class);
    $r = $svc->allocate($w['tenant']->id, $r->id, [['financial_obligation_id' => $w['payable']->id, 'amount_minor' => 30000]], $w['staff']->id);
    expect($r->status)->toBe('PARTIALLY_APPLIED')->and($unapplied()[0]['unapplied_minor'])->toBe(20000);
    $payable = DB::table('financial_obligations')->find($w['payable']->id);
    expect((int) $payable->outstanding_minor)->toBe(60000)->and(PremiumRemittanceService::remittanceStatus($payable))->toBe('PARTIALLY_REMITTED');
    $acc = app(SubledgerViews::class)->brokerInsurerAccount($w['tenant']->id, $w['carrier']->id, ['currency' => 'XAF']);
    expect($acc['premium_payable'])->toMatchArray(['gross_minor' => 90000, 'settled_minor' => 30000, 'outstanding_minor' => 60000])->and($acc['unapplied_remittance_minor'])->toBe(20000);

    $svc->allocate($w['tenant']->id, $r->id, [['financial_obligation_id' => $w['payable']->id, 'amount_minor' => 20000]], $w['staff']->id);
    expect($unapplied())->toBe([]);
    expect(DB::table('outbox_messages')->where('event_name', 'premium.remittance.allocated')->where('aggregate_id', $r->id)->count())->toBe(2);

    // Overdue remittance: an insurer payable past due with an outstanding balance.
    $late = fslCarrierPayable($w['tenant'], $w['policy'], 5000, now()->subDays(40)->toDateString());
    expect(PremiumRemittanceService::remittanceStatus(DB::table('financial_obligations')->find($late->id)))->toBe('OVERDUE');
    expect($svc->flagOverdue($w['tenant']->id))->toBe(1)->and($svc->flagOverdue($w['tenant']->id))->toBe(0);
});

it('maps the commission machine onto the spec lifecycle EXPECTED -> ACCRUED -> PAYABLE -> PARTIALLY_PAID -> PAID', function () {
    $w = fslWorld();
    $a = CommissionAccrual::find($w['accrual']->id);
    $states = [];
    foreach ([['CALCULATED', 0, 0], ['PENDING', 0, 0], ['VESTED', 10000, 0], ['VESTED', 10000, 4000], ['PAID', 10000, 10000]] as [$status, $vested, $paid]) {
        $a->forceFill(['status' => $status, 'vested_minor' => $vested, 'paid_minor' => $paid])->save();
        $states[] = CommissionMachine::specState($a->refresh());
    }
    expect($states)->toBe(['EXPECTED', 'ACCRUED', 'PAYABLE', 'PARTIALLY_PAID', 'PAID']);
    foreach (array_keys(CommissionMachine::definition()->states()) as $stored) {
        expect(SubledgerCatalogue::COMMISSION_STATES)->toContain(CommissionMachine::SPEC_STATE[$stored]);
    }
    $a->forceFill(['status' => 'VESTED', 'paid_minor' => 4000])->save();
    $row = collect(app(SubledgerViews::class)->agentLedger($w['tenant']->id, $w['agent']->id, 'PAYABLE_COMMISSION', [])['rows'])->firstWhere('commission_id', $a->id);
    expect($row['status'])->toBe('PARTIALLY_PAID')->and($row['outstanding_minor'])->toBe(6000); // net - paid - valid offsets
});

it('cancellation creates a clawback without deleting the original commission', function () {
    $w = fslWorld();
    $a = CommissionAccrual::find($w['accrual']->id);
    app(\App\Application\Commissions\Machine\CommissionTransitions::class)->apply($a, 'claw_back', null, 'POLICY_CANCELLED', [], 'VESTED');
    $a->forceFill(['clawed_back_minor' => 10000])->save();
    fslPost($w['tenant'], 'commission.clawed_back', $a->id, 10000);
    expect(CommissionAccrual::find($a->id))->not->toBeNull()->and(CommissionMachine::specState($a->refresh()))->toBe('CLAWED_BACK');
    app(\App\Application\Finance\Subledger\SubledgerProjector::class)->catchUp($w['tenant']->id);
    $entries = DB::table('finance_ledger_entries')->where('commission_id', $a->id)->get();
    expect($entries->pluck('journal_type')->unique()->sort()->values()->all())->toBe(['COMMISSION_ACCRUAL', 'COMMISSION_CLAWBACK']);
    $clawbacks = app(SubledgerViews::class)->agentLedger($w['tenant']->id, $w['agent']->id, 'CLAWBACKS', [])['rows'];
    expect($clawbacks)->toHaveCount(1)->and($clawbacks[0]['amount_minor'])->toBe(10000);
});

it('broker filters commission by insurer, client, insurance type, product, policy and date', function () {
    $w = fslWorld();
    $otherParty = \App\Models\Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Other client', 'status' => 'ACTIVE']);
    $otherCarrier = \App\Models\Carrier::create(['party_id' => \App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other insurer', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    $p2 = fslPolicy($w['tenant'], $otherParty, $otherCarrier, 'HEALTH', $w['broker']->id);
    $a2 = fslAccrual($w['tenant'], $p2['policy'], $w['agent'], 7000);
    Passport::actingAs($w['staff']);
    $ids = fn (string $q) => collect($this->getJson('/api/v1/finance/subledger/reports/AGENT_COMMISSION_LEDGER?'.$q, tenantHeaderFor($w['tenant']))->assertOk()->json('data.rows'))->pluck('commission_id')->all();
    expect($ids(''))->toEqualCanonicalizing([$w['accrual']->id, $a2->id]);
    expect($ids('insurer_id='.$otherCarrier->id))->toBe([$a2->id])
        ->and($ids('customer_id='.$w['party']->id))->toBe([$w['accrual']->id])
        ->and($ids('insurance_class_id=HEALTH'))->toBe([$a2->id])
        ->and($ids('product_id='.$w['product']->id))->toBe([$w['accrual']->id])
        ->and($ids('policy_id='.$p2['policy']->id))->toBe([$a2->id])
        ->and($ids('date_from='.now()->addDay()->toDateString()))->toBe([]);
});
