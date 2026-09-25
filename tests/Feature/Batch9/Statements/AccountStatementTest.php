<?php

declare(strict_types=1);

/*
 * Batch 9-8 — REQ-PAY-015 (ESR FIN-009..012): account statements derived
 * from transactions for customers, brokers, agents and carriers.
 */

use App\Application\Finance\Statements\AccountStatementService;
use App\Models\CommissionAccrual;
use App\Models\Partner;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\PartnerStatementItem;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Refund;
use App\Models\SettlementBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b98World(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $pay1 = makeMobileTestPayment($f['proposal'], $f['tenant'], ['amount_minor' => 60000]);
    $pay1->forceFill(['reconciled_at' => now()->subDays(19)])->save();
    $pay2 = makeMobileTestPayment($f['proposal'], $f['tenant'], ['amount_minor' => 40000]);
    $pay2->forceFill(['reconciled_at' => now()->subDays(2)])->save();
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['amount_minor' => 99999, 'status' => 'FAILED']);
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDays(20), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subDays(20), 'payment_intent_id' => $pay1->id,
    ]);
    $staff = makeAuthTestUser($f['tenant'], ['statements.read']);
    Refund::create(['tenant_id' => $f['tenant']->id, 'payment_intent_id' => $pay1->id, 'refund_number' => 'RF-'.Str::random(8), 'amount_minor' => 10000, 'currency' => 'XAF',
        'status' => 'COMPLETED', 'reason_code' => 'CUSTOMER_REQUEST', 'requested_by' => $staff->id, 'completed_at' => now()->subDay()]);

    $brokerParty = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B98 Broker', 'status' => 'ACTIVE']);
    $broker = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => $brokerParty->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    CommissionAccrual::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'partner_id' => $broker->id, 'rule_version' => 'v1', 'amount_minor' => 5000,
        'currency' => 'XAF', 'status' => 'VESTED', 'vested_minor' => 5000, 'clawed_back_minor' => 1000]);
    PartnerPayoutRequest::create(['tenant_id' => $f['tenant']->id, 'partner_id' => $broker->id, 'payout_number' => 'PO-'.Str::random(8), 'amount_minor' => 2000, 'currency' => 'XAF',
        'status' => 'PAID', 'destination_type' => 'MOBILE_MONEY', 'destination_encrypted' => 'x', 'requested_by' => $staff->id, 'paid_at' => now()]);
    SettlementBatch::create(['tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'settlement_number' => 'ST-'.Str::random(8), 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'net_amount_minor' => 50000, 'currency' => 'XAF', 'status' => 'PAID', 'prepared_by' => $staff->id, 'paid_at' => now()]);

    return $f + ['policy' => $policy, 'broker' => $broker, 'staff' => $staff];
}

function b98Period(): string
{
    return 'from='.now()->subDays(5)->toDateString().'&to='.now()->toDateString().'&currency=XAF';
}

it('builds a customer statement with opening balance, typed lines, running balance and closing balance', function () {
    $w = b98World();
    $s = app(AccountStatementService::class)->build($w['tenant']->id, 'customer', $w['party']->id, now()->subDays(5)->toDateString(), now()->toDateString(), 'XAF');

    // opening: premium 100 000 − payment 60 000; period: payment −40 000, refund +10 000 (failed payment ignored).
    expect($s['opening_balance_minor'])->toBe(40000)
        ->and(array_column($s['lines'], 'line_type'))->toBe(['PAYMENT', 'REFUND'])
        ->and($s['lines'][0]['balance_minor'])->toBe(0)
        ->and($s['closing_balance_minor'])->toBe(10000)
        ->and($s['totals_by_type'])->toBe(['PAYMENT' => -40000, 'REFUND' => 10000])
        ->and($s['balance_meaning'])->toBe('OWED_BY_SUBJECT');
});

it('builds broker and carrier statements from commissions, payouts, refunds and settlements', function () {
    $w = b98World();
    $svc = app(AccountStatementService::class);
    $from = now()->subDays(30)->toDateString();

    $b = $svc->build($w['tenant']->id, 'broker', $w['broker']->id, $from, now()->toDateString(), 'XAF');
    expect($b['opening_balance_minor'])->toBe(0)->and($b['closing_balance_minor'])->toBe(5000 - 1000 - 2000)
        ->and($b['totals_by_type'])->toMatchArray(['COMMISSION' => 5000, 'ADJUSTMENT' => -1000, 'PAYMENT' => -2000]);

    // Broker is not an agent.
    expect(fn () => $svc->build($w['tenant']->id, 'agent', $w['broker']->id, $from, now()->toDateString(), 'XAF'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $c = $svc->build($w['tenant']->id, 'carrier', $w['carrier']->id, $from, now()->toDateString(), 'XAF');
    expect($c['totals_by_type'])->toMatchArray(['PREMIUM_DUE' => 100000, 'COMMISSION' => -4000, 'REFUND' => -10000, 'PAYMENT' => -50000])
        ->and($c['closing_balance_minor'])->toBe(36000);
});

it('serves staff JSON and PDF behind statements.read', function () {
    $w = b98World();
    Passport::actingAs($w['staff']);
    $this->getJson('/api/v1/finance/statements/customer/'.$w['party']->id.'?'.b98Period(), tenantHeaderFor($w['tenant']))
        ->assertOk()->assertJsonPath('data.closing_balance_minor', 10000)->assertJsonPath('data.subject.type', 'CUSTOMER');
    $pdf = $this->get('/api/v1/finance/statements/carrier/'.$w['carrier']->id.'?format=pdf&'.b98Period(), tenantHeaderFor($w['tenant']));
    $pdf->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    Passport::actingAs(makeAuthTestUser($w['tenant'], ['policies.read']));
    $this->getJson('/api/v1/finance/statements/customer/'.$w['party']->id.'?'.b98Period(), tenantHeaderFor($w['tenant']))->assertForbidden();
});

it('lets a customer read only their own statement on mobile', function () {
    $w = b98World();
    Passport::actingAs($w['user']);
    $this->getJson('/api/v1/mobile/statements?'.b98Period(), tenantHeaderFor($w['tenant']))
        ->assertOk()->assertJsonPath('data.subject.id', $w['party']->id)->assertJsonPath('data.closing_balance_minor', 10000);
    $this->get('/api/v1/mobile/statements?format=pdf&'.b98Period(), tenantHeaderFor($w['tenant']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // No write surface.
    $this->postJson('/api/v1/mobile/statements', [], tenantHeaderFor($w['tenant']))->assertStatus(405);
    // Staff endpoint is not reachable for a customer.
    $this->getJson('/api/v1/finance/statements/customer/'.$w['party']->id.'?'.b98Period(), tenantHeaderFor($w['tenant']))->assertForbidden();
});

it('renders a persisted partner statement in the same shape', function () {
    $w = b98World();
    $s = PartnerStatement::create(['tenant_id' => $w['tenant']->id, 'partner_id' => $w['broker']->id, 'statement_number' => 'PST-'.Str::random(8), 'period_start' => now()->subDays(30)->toDateString(),
        'period_end' => now()->toDateString(), 'currency' => 'XAF', 'status' => 'DRAFT', 'opening_balance_minor' => 100, 'earned_minor' => 5000, 'clawed_back_minor' => 1000, 'paid_minor' => 0,
        'closing_balance_minor' => 4100, 'content_hash' => str_repeat('a', 64), 'idempotency_key' => Str::uuid()->toString(), 'prepared_by' => $w['staff']->id]);
    PartnerStatementItem::create(['partner_statement_id' => $s->id, 'entry_type' => 'COMMISSION', 'reference_type' => 'commission_accrual', 'reference_id' => Str::uuid()->toString(),
        'amount_minor' => 4000, 'currency' => 'XAF', 'occurred_at' => now(), 'metadata' => []]);
    Passport::actingAs($w['staff']);
    $this->getJson('/api/v1/finance/partner-statements/'.$s->id, tenantHeaderFor($w['tenant']))
        ->assertOk()->assertJsonPath('data.source', 'PARTNER_STATEMENT')->assertJsonPath('data.lines.0.balance_minor', 4100)->assertJsonPath('data.closing_balance_minor', 4100);

    // The derived broker statement for the same period links the persisted one.
    $d = app(AccountStatementService::class)->build($w['tenant']->id, 'broker', $w['broker']->id, now()->subDays(30)->toDateString(), now()->toDateString(), 'XAF');
    expect($d['persisted_statement_id'])->toBe($s->id);
});
