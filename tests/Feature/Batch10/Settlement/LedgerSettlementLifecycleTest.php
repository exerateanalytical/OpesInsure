<?php

declare(strict_types=1);

/*
 * Batch 10-4 — REQ-STL-001 broker–insurer settlement calculated from obligations (WF-068/069, SSR FIN-019)
 * and REQ-DUP-011 one settlement service + resource behind settlements/{batch} and carrier-settlements/*.
 */

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Settlements\SettlementService;
use App\Models\CommissionAccrual;
use App\Models\FinancialPostingProfile;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\SettlementBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B104_PERMS = ['settlement.read', 'settlement.prepare', 'settlement.approve', 'settlement.submit', 'settlement.confirm', 'settlement.reconcile', 'settlement.reverse'];

function b104Policy(array $f, int $premium, int $collected, ?string $mode, int $commission = 0, ?string $brokerId = null): Policy
{
    $pay = makeMobileTestPayment($f['proposal'], $f['tenant'], ['amount_minor' => $premium]);
    if ($mode) {
        $pay->forceFill(['collection_mode' => $mode])->save();
    }
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDays(10), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => $premium, 'issued_at' => now()->subDays(10), 'payment_intent_id' => $pay->id,
    ]);
    $o = app(ObligationService::class)->create([
        'tenant_id' => $f['tenant']->id, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => 'policy', 'source_id' => $policy->id,
        'policy_id' => $policy->id, 'currency' => 'XAF', 'amount_minor' => $premium, 'due_at' => now()->subDays(10),
        'creditor_type' => 'carrier', 'creditor_id' => $f['carrier']->id,
    ]);
    if ($collected > 0) {
        app(ObligationService::class)->settle($o->id, $collected, 'pay:'.$pay->id);
    }
    if ($commission > 0) {
        CommissionAccrual::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'partner_id' => $brokerId, 'rule_version' => 'v1',
            'amount_minor' => $commission, 'currency' => 'XAF', 'status' => 'ACCRUED', 'vested_minor' => 0, 'clawed_back_minor' => 0]);
    }

    return $policy;
}

function b104World(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $broker = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B104 Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $maker = makeAuthTestUser($f['tenant'], B104_PERMS);
    $checker = makeAuthTestUser($f['tenant'], B104_PERMS);
    $acc = function (string $code, string $type): string {
        $id = (string) Str::uuid();
        DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => null, 'code' => $code, 'name' => $code, 'type' => $type, 'currency' => 'XAF',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    foreach (['settlement.approved', 'settlement.settled'] as $i => $event) {
        FinancialPostingProfile::create(['tenant_id' => null, 'event_type' => $event, 'currency' => 'XAF', 'debit_account_id' => $acc("B104-DR-$i-".Str::random(4), 'LIABILITY'),
            'credit_account_id' => $acc("B104-CR-$i-".Str::random(4), 'LIABILITY'), 'status' => 'APPROVED', 'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()]);
    }

    return $f + ['broker' => $broker, 'maker' => $maker, 'checker' => $checker];
}

function b104Draft(array $w, string $key = 'k1'): string
{
    Passport::actingAs($w['maker']);

    return test()->postJson('/api/v1/broker-settlements', [
        'carrier_id' => $w['carrier']->id, 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'currency' => 'XAF', 'idempotency_key' => $key,
    ], tenantHeader($w['tenant']))->assertStatus(201)->json('data.batch.id');
}

it('calculates net due per collection mode from obligations and runs the full lifecycle to RECONCILED', function () {
    $w = b104World();
    $a = b104Policy($w, 100000, 100000, 'BROKER_COLLECTION', 15000, $w['broker']->id);
    b104Policy($w, 50000, 50000, 'INSURER_COLLECTION', 7500, $w['broker']->id);
    $c = b104Policy($w, 60000, 40000, 'MOBILE_MONEY', 6000);
    b104Policy($w, 30000, 0, 'BROKER_COLLECTION');
    $h = tenantHeader($w['tenant']);

    $id = b104Draft($w);
    $data = $this->postJson("/api/v1/broker-settlements/{$id}/calculate", [], $h)->assertOk()->json('data');
    expect($data['batch']['status'])->toBe('CALCULATED')
        ->and((int) $data['batch']['net_amount_minor'])->toBe(85000 + 34000)
        ->and($data['items'])->toHaveCount(2)
        ->and($data['lifecycle']['next'])->toContain('REVIEW');
    $items = collect($data['items'])->keyBy('policy_id');
    expect((int) $items[$a->id]['commission_minor'])->toBe(15000)->and($items[$a->id]['collection_mode'])->toBe('BROKER_COLLECTION')
        ->and((int) $items[$c->id]['gross_premium_minor'])->toBe(40000)->and((int) $items[$c->id]['net_due_minor'])->toBe(34000);
    $summary = json_decode($data['batch']['calculation_summary'], true);
    expect($summary['excluded_carrier_collected_minor'])->toBe(50000);

    $payable = DB::table('financial_obligations')->where('id', $items[$a->id]['financial_obligation_id'])->first();
    expect($payable->kind)->toBe('PAYABLE')->and($payable->creditor_id)->toBe($w['carrier']->id)
        ->and($payable->debtor_type)->toBe('partner')->and($payable->debtor_id)->toBe($w['broker']->id)->and((int) $payable->amount_minor)->toBe(85000);

    // cannot skip review
    $this->postJson("/api/v1/broker-settlements/{$id}/approve", [], $h)->assertStatus(422);
    $this->postJson("/api/v1/broker-settlements/{$id}/review", [], $h)->assertOk();
    // maker cannot approve
    $this->postJson("/api/v1/broker-settlements/{$id}/approve", [], $h)->assertStatus(422);

    Passport::actingAs($w['checker']);
    $this->postJson("/api/v1/broker-settlements/{$id}/approve", ['notes' => 'Checked against the broker collection report.'], $h)->assertOk()->assertJsonPath('data.batch.status', 'APPROVED');
    expect(DB::table('journals')->where(['reference_type' => 'settlement.approved', 'reference_id' => $id])->count())->toBe(1)
        ->and(DB::table('settlement_approvals')->where('settlement_batch_id', $id)->value('decision'))->toBe('APPROVED');

    $this->postJson("/api/v1/broker-settlements/{$id}/process", [], $h)->assertOk()->assertJsonPath('data.batch.status', 'PROCESSING');
    $this->postJson("/api/v1/broker-settlements/{$id}/fail", ['reason' => 'Bank rejected'], $h)->assertOk()->assertJsonPath('data.batch.status', 'APPROVED');
    $this->postJson("/api/v1/broker-settlements/{$id}/process", [], $h)->assertOk();
    $this->postJson("/api/v1/broker-settlements/{$id}/reconcile", ['reference' => 'X'], $h)->assertStatus(422);
    $this->postJson("/api/v1/broker-settlements/{$id}/settle", ['bank_reference' => 'BANK-1'], $h)->assertOk()->assertJsonPath('data.batch.status', 'SETTLED');

    expect(DB::table('financial_obligations')->whereIn('id', collect($data['items'])->pluck('financial_obligation_id'))->pluck('status')->unique()->all())->toBe(['SETTLED'])
        ->and(DB::table('journals')->where(['reference_type' => 'settlement.settled', 'reference_id' => $id])->count())->toBe(1);

    $this->postJson("/api/v1/broker-settlements/{$id}/reconcile", ['reference' => 'STMT-9'], $h)->assertOk()->assertJsonPath('data.batch.status', 'RECONCILED');
    $events = DB::table('outbox_messages')->where('aggregate_id', $id)->pluck('event_name')->all();
    expect($events)->toContain('settlement.calculated', 'settlement.approved', 'settlement.settled', 'settlement.reconciled');
});

it('only includes what earlier live batches did not, and a rejection cancels the run payables', function () {
    $w = b104World();
    $p = b104Policy($w, 100000, 60000, 'BROKER_COLLECTION', 10000, $w['broker']->id);
    $svc = app(SettlementService::class);

    $first = SettlementBatch::find(b104Draft($w, 'a'));
    $first = $svc->calculate($first, $w['maker']);
    expect((int) $first->net_amount_minor)->toBe(50000);

    // more premium collected → second batch only takes the delta
    $recv = DB::table('financial_obligations')->where(['policy_id' => $p->id, 'kind' => 'RECEIVABLE'])->value('id');
    app(ObligationService::class)->settle($recv, 40000, 'second');
    $second = $svc->calculate(SettlementBatch::find(b104Draft($w, 'b')), $w['maker']);
    expect((int) $second->net_amount_minor)->toBe(40000);

    // rejecting the first returns it to DRAFT and cancels its payable
    $svc->submitForReview($first, $w['maker']);
    $payable = DB::table('settlement_items')->where('settlement_batch_id', $first->id)->value('financial_obligation_id');
    $first = $svc->reject($first, $w['checker'], 'Commission figure disputed');
    expect($first->status)->toBe('DRAFT')->and(DB::table('financial_obligations')->where('id', $payable)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('settlement_items')->where('settlement_batch_id', $first->id)->count())->toBe(0);

    // recalculating it now picks up the first 60 000 again (net of commission)
    expect((int) $svc->calculate($first, $w['maker'])->net_amount_minor)->toBe(50000);
});

it('serves one settlement resource on settlements/{batch} and its carrier-settlements alias; legacy routes dispatch by basis', function () {
    $w = b104World();
    b104Policy($w, 100000, 100000, 'BANK', 5000);
    $h = tenantHeader($w['tenant']);
    $id = b104Draft($w);
    $this->postJson("/api/v1/broker-settlements/{$id}/calculate", [], $h)->assertOk();
    $this->postJson("/api/v1/broker-settlements/{$id}/review", [], $h)->assertOk();

    $a = $this->getJson("/api/v1/settlements/{$id}", $h)->assertOk()->json('data');
    $b = $this->getJson("/api/v1/carrier-settlements/{$id}", $h)->assertOk()->json('data');
    expect($a)->toBe($b)->and(array_keys($a))->toBe(['batch', 'items', 'approvals', 'lifecycle'])->and($a['lifecycle']['basis'])->toBe('OBLIGATIONS');

    // carrier-settlements/{id}/approve and /submit reach the ledger lifecycle for an OBLIGATIONS batch
    Passport::actingAs($w['checker']);
    $this->postJson("/api/v1/carrier-settlements/{$id}/approve", [], $h)->assertOk()->assertJsonPath('status', 'APPROVED');
    $this->postJson("/api/v1/carrier-settlements/{$id}/submit", [], $h)->assertOk()->assertJsonPath('status', 'PROCESSING');
    $this->postJson("/api/v1/carrier-settlements/{$id}/reverse", ['reason_code' => 'X'], $h)->assertStatus(422);

    // the Wave6 service itself refuses to move a ledger batch
    expect(fn () => app(\App\Application\FinancialDistribution\CarrierSettlementService::class)->submit(SettlementBatch::find($id), $w['checker']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    // tenant isolation on the alias
    $other = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($other, ['settlement.read']));
    $this->getJson("/api/v1/carrier-settlements/{$id}", tenantHeader($other))->assertStatus(404);
});
