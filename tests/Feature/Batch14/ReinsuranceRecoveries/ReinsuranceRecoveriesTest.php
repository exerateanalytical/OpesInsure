<?php

declare(strict_types=1);

/**
 * Batch 14C / E7 — REQ-REI-004 reinsurance recoveries: estimate from claim reserves/payments against the
 * policy's cessions (QS / surplus / XL layers / stop loss / facultative), large-loss notification, XL
 * reinstatement premium, billing as RECEIVABLE obligations + ledger, receipts, disputes, closing.
 */

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use App\Application\Reinsurance\CessionService;
use App\Application\Reinsurance\Recoveries\RecoveryCalculator;
use App\Models\Claim;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const E7_PERMS = ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate',
    'reinsurance.recoveries.view', 'reinsurance.recoveries.manage', 'reinsurance.recoveries.approve', 'reinsurance.recoveries.bill', 'reinsurance.recoveries.settle'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->maker = makeAuthTestUser($this->tenant, E7_PERMS);
    $this->checker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve']);
});

function e7Reinsurer(string $code): string
{
    Passport::actingAs(test()->maker, [], 'api');

    $id = test()->postJson('/api/v1/reinsurance/reinsurers', ['code' => $code, 'name' => "Re {$code}", 'role' => 'REINSURER'], test()->h)->assertCreated()->json('data.id');
    \Illuminate\Support\Facades\DB::table('reinsurers')->where('id', $id)->update(['approved_security_status' => 'TENANT_APPROVED']); // Gap Closure 07 approved-security gate

    return $id;
}

function e7Treaty(string $code, string $type, array $terms, array $participants): string
{
    Passport::actingAs(test()->maker, [], 'api');
    $treaty = test()->postJson('/api/v1/reinsurance/treaties', ['code' => $code, 'name' => $code, 'treaty_type' => $type, 'currency' => 'XAF'], test()->h)->assertCreated()->json('data.id');
    $version = test()->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", $terms + ['effective_from' => '2026-01-01', 'participants' => $participants], test()->h)->assertCreated()->json('data.id');
    Passport::actingAs(test()->checker, [], 'api');
    test()->postJson("/api/v1/reinsurance/treaty-versions/{$version}/activate", ['reason' => 'Signed slip'], test()->h)->assertOk();
    Passport::actingAs(test()->maker, [], 'api');

    return $treaty;
}

function e7Policy(int $premium = 1_000_000, int $sumInsured = 100_000_000): Policy
{
    $f = test()->f;
    $p = Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-11-01', 'coverage_ends_at' => '2027-10-31',
        'terms_snapshot' => ['line_code' => 'PROPERTY', 'sum_insured_minor' => $sumInsured], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => $premium, 'issued_at' => now()]);
    app(CessionService::class)->cedePolicy($f['tenant']->id, $p->id);

    return $p;
}

function e7Claim(Policy $p, int $reserve): Claim
{
    return makeMobileTestClaim(test()->tenant, $p, test()->f['party'], ['current_reserve_minor' => $reserve]);
}

function e7Pay(Claim $c, int $amount): void
{
    $u = test()->maker;
    $d = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $d, 'claim_id' => $c->id, 'decision' => 'APPROVED', 'currency' => 'XAF', 'reason_code' => 'COVERED', 'rationale' => 'ok', 'proposed_by' => $u->id,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('claim_payments')->insert(['id' => (string) Str::uuid(), 'claim_id' => $c->id, 'claim_decision_id' => $d, 'payee_party_id' => test()->f['party']->id, 'amount_minor' => $amount,
        'currency' => 'XAF', 'status' => 'PAID', 'idempotency_key' => Str::random(12), 'requested_by' => $u->id, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

it('REQ-REI-004: pure calculator applies FAC -> QS -> surplus -> XL layers -> stop loss with reinstatement premium', function () {
    $calc = new RecoveryCalculator;
    $out = $calc->calculate(10_000_000, [
        ['source_type' => 'TREATY', 'source_key' => 'x', 'treaty_type' => 'EXCESS_OF_LOSS', 'ceded_percent' => 0,
            'layers' => [['layer' => 1, 'attachment_minor' => 2_000_000, 'limit_minor' => 3_000_000, 'premium_minor' => 60_000]],
            'treaty_layers' => [['layer' => 1, 'reinstatements' => 1, 'reinstatement_premium_percent' => 100]], 'shares' => [['reinsurer_id' => 'a', 'share_percent' => 100]]],
        ['source_type' => 'TREATY', 'source_key' => 'q', 'treaty_type' => 'QUOTA_SHARE', 'ceded_percent' => 40, 'shares' => [['reinsurer_id' => 'a', 'share_percent' => 60], ['reinsurer_id' => 'b', 'share_percent' => 40]]],
        ['source_type' => 'FACULTATIVE', 'source_key' => 'f', 'treaty_type' => 'FACULTATIVE', 'ceded_percent' => 10, 'shares' => [['reinsurer_id' => 'c', 'share_percent' => 100]]],
        ['source_type' => 'TREATY', 'source_key' => 's', 'treaty_type' => 'STOP_LOSS', 'ceded_percent' => 0, 'shares' => [['reinsurer_id' => 'a', 'share_percent' => 100]]],
    ], fn ($s, $net) => 100_000);
    $by = collect($out)->keyBy('source_key');
    // FAC 10% of 10M = 1M; QS 40% of 9M = 3.6M (2.16M/1.44M); XL on 5.4M: 3M layer fully hit; SL 100k on the 2.4M retained.
    expect($by['f']['recoverable_minor'])->toBe(1_000_000)
        ->and($by['q']['recoverable_minor'])->toBe(3_600_000)->and($by['q']['share_amounts'])->toBe([2_160_000, 1_440_000])
        ->and($by['x']['subject_loss_minor'])->toBe(5_400_000)->and($by['x']['recoverable_minor'])->toBe(3_000_000)
        ->and($by['x']['reinstatement_premium_minor'])->toBe(60_000)
        ->and($by['s']['recoverable_minor'])->toBe(100_000);
    // No reinstatement percent configured = no reinstatement premium (no invented default).
    $none = $calc->calculate(10_000_000, [['source_type' => 'TREATY', 'source_key' => 'x', 'treaty_type' => 'EXCESS_OF_LOSS',
        'layers' => [['layer' => 1, 'attachment_minor' => 0, 'limit_minor' => 1_000_000, 'premium_minor' => 60_000]], 'treaty_layers' => [['layer' => 1, 'reinstatements' => 2]], 'shares' => []]]);
    expect($none[0]['reinstatement_premium_minor'])->toBe(0);
    expect(RecoveryCalculator::stopLossAggregate(900, 1000, 80, 50))->toBe(100);
});

it('REQ-REI-004: estimate from reserve + payments against QS and XL cessions; full lifecycle to CLOSED with obligations and ledger', function () {
    $a = e7Reinsurer('A');
    $b = e7Reinsurer('B');
    e7Treaty('QS', 'QUOTA_SHARE', ['cession_percent' => 50], [['reinsurer_id' => $a, 'share_percent' => 70], ['reinsurer_id' => $b, 'share_percent' => 30]]);
    e7Treaty('XL', 'EXCESS_OF_LOSS', ['layers' => [['layer' => 1, 'attachment_minor' => 1_000_000, 'limit_minor' => 4_000_000, 'rate_percent' => 5, 'reinstatements' => 1, 'reinstatement_premium_percent' => 100]]],
        [['reinsurer_id' => $b, 'share_percent' => 100]]);
    $policy = e7Policy();
    $claim = e7Claim($policy, 6_000_000);
    e7Pay($claim, 2_000_000);

    $data = $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk()->json('data');
    expect($data['gross_incurred_minor'])->toBe(8_000_000)->and($data['gross_paid_minor'])->toBe(2_000_000);
    $by = collect($data['recoveries'])->keyBy('treaty_type');
    // QS 50% of 8M = 4M; XL on retained 4M: 3M in layer 1M xs... (4M-1M = 3M).
    expect((int) $by['QUOTA_SHARE']['recoverable_minor'])->toBe(4_000_000)->and((int) $by['QUOTA_SHARE']['recoverable_paid_minor'])->toBe(1_000_000)
        ->and((int) $by['EXCESS_OF_LOSS']['recoverable_minor'])->toBe(3_000_000)
        ->and($by['QUOTA_SHARE']['status'])->toBe('ESTIMATED')
        ->and(collect($by['QUOTA_SHARE']['shares'])->sum('recoverable_minor'))->toBe(4_000_000);
    // XL layer premium = 5% of retained premium 500k = 25k; reinstatement = 25k * 3M/4M * 100% = 18,750.
    expect((int) $by['EXCESS_OF_LOSS']['reinstatement_premium_minor'])->toBe(18_750);

    // Idempotent re-estimate; reserve change refreshes the estimate.
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk();
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.recovery.estimated')->count())->toBe(2);
    DB::table('claims')->where('id', $claim->id)->update(['current_reserve_minor' => 4_000_000]);
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk();
    $qs = DB::table('reinsurance_recoveries')->where('claim_id', $claim->id)->where('treaty_type', 'QUOTA_SHARE')->first();
    expect((int) $qs->recoverable_minor)->toBe(3_000_000);

    // No threshold configured: no large-loss notification.
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.recovery.large_loss_notified')->exists())->toBeFalse();

    // Lifecycle.
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/bill", ['due_at' => '2026-12-31'], $this->h)->assertUnprocessable();
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/notify", [], $this->h)->assertOk()->assertJsonPath('data.status', 'NOTIFIED');
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/agree", ['amount_minor' => 9_000_000], $this->h)->assertUnprocessable();
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/agree", [], $this->h)->assertOk()->assertJsonPath('data.agreed_minor', 3_000_000);
    // Agreed amounts are frozen on re-estimate.
    DB::table('claims')->where('id', $claim->id)->update(['current_reserve_minor' => 1]);
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk();
    expect((int) DB::table('reinsurance_recoveries')->where('id', $qs->id)->value('recoverable_minor'))->toBe(3_000_000);

    $billed = $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/bill", ['due_at' => '2026-12-31'], $this->h)->assertOk()->json('data');
    expect($billed['status'])->toBe('BILLED')->and($billed['billed_minor'])->toBe(3_000_000);
    $obls = DB::table('financial_obligations')->where('source_type', 'reinsurance_recovery')->where('source_id', $qs->id)->get();
    expect($obls)->toHaveCount(2)->and($obls->pluck('kind')->unique()->all())->toBe(['RECEIVABLE'])->and((int) $obls->sum('amount_minor'))->toBe(3_000_000)
        ->and((int) $obls->firstWhere('debtor_id', $a)->amount_minor)->toBe(2_100_000);
    expect(DB::table('journals')->where('reference_type', 'reinsurance.recovery.billed')->where('reference_id', $qs->id)->count())->toBe(1);

    // Dispute from BILLED then resume billing.
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/dispute", ['reason' => 'Reinsurer queries the reserve'], $this->h)->assertOk()->assertJsonPath('data.status', 'DISPUTED');
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/bill", ['due_at' => '2026-12-31'], $this->h)->assertOk()->assertJsonPath('data.status', 'BILLED');
    expect(DB::table('financial_obligations')->where('source_id', $qs->id)->count())->toBe(2);

    // Receipts, idempotent per reference, capped at outstanding.
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/receipts", ['reinsurer_id' => $a, 'amount_minor' => 2_100_001, 'reference' => 'SW-0'], $this->h)->assertUnprocessable();
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/receipts", ['reinsurer_id' => $a, 'amount_minor' => 2_100_000, 'reference' => 'SW-1'], $this->h)->assertOk()->assertJsonPath('data.status', 'BILLED');
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/receipts", ['reinsurer_id' => $a, 'amount_minor' => 2_100_000, 'reference' => 'SW-1'], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/receipts", ['reinsurer_id' => $b, 'amount_minor' => 900_000, 'reference' => 'SW-2'], $this->h)->assertOk()->assertJsonPath('data.status', 'SETTLED');
    expect(DB::table('financial_obligations')->where('source_id', $qs->id)->pluck('status')->unique()->all())->toBe(['SETTLED'])
        ->and(DB::table('journals')->where('reference_type', 'reinsurance.recovery.settled')->count())->toBe(2)
        ->and(DB::table('outbox_messages')->where('event_name', 'reinsurance.recovery.settled')->count())->toBe(2);
    $this->postJson("/api/v1/reinsurance/recoveries/{$qs->id}/close", ['reason' => 'Fully recovered'], $this->h)->assertOk()->assertJsonPath('data.status', 'CLOSED');

    // XL billing raises reinstatement premium payable to the reinsurer.
    DB::table('claims')->where('id', $claim->id)->update(['current_reserve_minor' => 6_000_000]);
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk();
    $xl = DB::table('reinsurance_recoveries')->where('claim_id', $claim->id)->where('treaty_type', 'EXCESS_OF_LOSS')->value('id');
    $this->postJson("/api/v1/reinsurance/recoveries/{$xl}/notify", [], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/recoveries/{$xl}/agree", [], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/recoveries/{$xl}/bill", ['due_at' => '2026-12-31'], $this->h)->assertOk();
    $rp = DB::table('financial_obligations')->where('source_type', 'reinsurance_reinstatement')->where('source_id', $xl)->first();
    expect($rp->kind)->toBe('PAYABLE')->and($rp->creditor_id)->toBe($b)->and((int) $rp->amount_minor)->toBeGreaterThan(0);

    // REI screens.
    expect($this->getJson('/api/v1/reinsurance/recoveries?status=CLOSED', $this->h)->assertOk()->json('data'))->toHaveCount(1);
    $sum = $this->getJson('/api/v1/reinsurance/recoveries/summary', $this->h)->assertOk()->json('data');
    expect(collect($sum['by_reinsurer'])->firstWhere('reinsurer_id', $b)['outstanding_minor'])->toBeGreaterThan(0);
    expect(DB::table('audit_log')->where('action', 'reinsurance.recovery.agreed')->exists())->toBeTrue();
});

it('REQ-REI-004: large-loss notification only when a per-treaty threshold is configured', function () {
    $a = e7Reinsurer('A');
    $treaty = e7Treaty('QS', 'QUOTA_SHARE', ['cession_percent' => 30], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    expect(DB::table('reinsurance_treaties')->where('id', $treaty)->value('large_loss_threshold_minor'))->toBeNull();
    $policy = e7Policy();

    $small = e7Claim($policy, 400_000);
    $this->postJson("/api/v1/reinsurance/claims/{$small->id}/recoveries/estimate", [], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/large-loss-threshold", ['threshold_minor' => 500_000, 'reason' => 'Treaty clause 12'], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/claims/{$small->id}/recoveries/estimate", [], $this->h)->assertOk();
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.recovery.large_loss_notified')->exists())->toBeFalse();

    $big = e7Claim($policy, 800_000);
    $rec = $this->postJson("/api/v1/reinsurance/claims/{$big->id}/recoveries/estimate", [], $this->h)->assertOk()->json('data.recoveries.0');
    expect($rec['status'])->toBe('NOTIFIED')->and($rec['large_loss_notified_at'])->not->toBeNull();
    $this->postJson("/api/v1/reinsurance/claims/{$big->id}/recoveries/estimate", [], $this->h)->assertOk();
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.recovery.large_loss_notified')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'reinsurance.treaty.large_loss_threshold_changed')->exists())->toBeTrue();
    expect($this->getJson('/api/v1/reinsurance/recoveries?large_loss=1', $this->h)->assertOk()->json('data'))->toHaveCount(1);
});

it('REQ-REI-004: stop loss recovers on the aggregate retained loss ratio across claims', function () {
    $a = e7Reinsurer('A');
    // Attach at 80% loss ratio, limit 50% of subject premium 1,000,000.
    e7Treaty('SL', 'STOP_LOSS', ['rate_percent' => 2, 'attachment_ratio' => 80, 'limit_ratio' => 50], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    $policy = e7Policy(1_000_000);
    $c1 = e7Claim($policy, 600_000);
    $c2 = e7Claim($policy, 500_000);
    $r1 = $this->postJson("/api/v1/reinsurance/claims/{$c1->id}/recoveries/estimate", [], $this->h)->assertOk()->json('data.recoveries.0');
    expect((int) $r1['recoverable_minor'])->toBe(0);
    $r2 = $this->postJson("/api/v1/reinsurance/claims/{$c2->id}/recoveries/estimate", [], $this->h)->assertOk()->json('data.recoveries.0');
    expect((int) $r2['recoverable_minor'])->toBe(300_000); // 1.1M aggregate - 800k attachment
    $this->postJson("/api/v1/reinsurance/recoveries/{$r1['id']}/close", ['reason' => 'Below stop-loss attachment'], $this->h)->assertOk()->assertJsonPath('data.status', 'CLOSED');
});

it('REQ-REI-004: bound E6 facultative placements are recovery sources exactly once; permissions and tenancy enforced', function () {
    $a = e7Reinsurer('A');
    $fac = e7Reinsurer('FAC');
    e7Treaty('QS', 'QUOTA_SHARE', ['cession_percent' => 50], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    $policy = e7Policy();
    $claim = e7Claim($policy, 1_000_000);

    // A real E6 placement: 20 % facultative, bound through the maker-checker flow, recorded once by CessionService.
    $fm = makeAuthTestUser($this->tenant, [...E7_PERMS, 'reinsurance.facultative.view', 'reinsurance.facultative.manage']);
    $fc = makeAuthTestUser($this->tenant, ['reinsurance.facultative.view', 'reinsurance.facultative.approve']);
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $this->f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $fc->id,
        'authority_type' => 'FACULTATIVE_APPROVE', 'max_amount_minor' => 500_000_000, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($fm, [], 'api');
    $slip = $this->postJson('/api/v1/reinsurance/facultative', ['policy_id' => $policy->id, 'risk_description' => 'Warehouse', 'placed_share_percent' => 20,
        'period_from' => '2026-11-01', 'period_to' => '2027-10-31', 'participants' => [['reinsurer_id' => $fac, 'offered_percent' => 100]]], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/reinsurance/facultative/{$slip}/lines", ['lines' => [['reinsurer_id' => $fac, 'written_percent' => 100]]], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/facultative/{$slip}/submit", [], $this->h)->assertOk();
    Passport::actingAs($fc, [], 'api');
    $this->postJson("/api/v1/reinsurance/facultative/{$slip}/approve", ['reason' => 'Signed slip'], $this->h)->assertOk()->assertJsonPath('data.status', 'BOUND');

    Passport::actingAs($this->maker, [], 'api');
    $recs = $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/preview", [], $this->h)->assertOk()->json('data.recoveries');
    $by = collect($recs)->keyBy('treaty_type');
    // FAC 20 % of 1m = 200k; QS 50 % of the remaining 800k = 400k. The placement is one source, never two.
    expect($by['FACULTATIVE']['recoverable_minor'])->toBe(200_000)->and($by['QUOTA_SHARE']['recoverable_minor'])->toBe(400_000)
        ->and(collect($recs)->where('source_type', 'FACULTATIVE'))->toHaveCount(1)
        ->and(array_sum(array_column($recs, 'recoverable_minor')))->toBe(600_000);
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertOk();
    expect(DB::table('reinsurance_recoveries')->where('claim_id', $claim->id)->where('source_type', 'FACULTATIVE')->count())->toBe(1)
        ->and(DB::table('reinsurance_recoveries')->where('claim_id', $claim->id)->where('source_type', 'FACULTATIVE')->value('source_key'))->toBe($slip);

    $viewer = makeAuthTestUser($this->tenant, ['reinsurance.recoveries.view']);
    Passport::actingAs($viewer, [], 'api');
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], $this->h)->assertForbidden();
    $this->getJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries", $this->h)->assertOk();

    $other = makeAuthTestTenant('e7-other');
    Passport::actingAs(makeAuthTestUser($other, E7_PERMS), [], 'api');
    $this->postJson("/api/v1/reinsurance/claims/{$claim->id}/recoveries/estimate", [], ['X-Tenant-Id' => $other->id])->assertNotFound();
});

it('REQ-REI-004: events are catalogued and accounting events have default mappings', function () {
    foreach (['estimated', 'notified', 'large_loss_notified', 'agreed', 'billed', 'settled', 'disputed', 'closed'] as $e) {
        expect(DomainEventCatalogue::has("reinsurance.recovery.{$e}"))->toBeTrue();
    }
    expect(DefaultChartOfAccounts::ACCOUNTS)->toHaveKey('416000');
    $billed = DB::table('accounting_event_mappings')->where(['event_code' => 'reinsurance.recovery.billed', 'status' => 'ACTIVE'])->whereNull('tenant_id')->first();
    $settled = DB::table('accounting_event_mappings')->where(['event_code' => 'reinsurance.recovery.settled', 'status' => 'ACTIVE'])->whereNull('tenant_id')->first();
    expect($billed->debit_account_code)->toBe('416000')->and($billed->credit_account_code)->toBe('601000')
        ->and($settled->debit_account_code)->toBe('521000')->and($settled->credit_account_code)->toBe('416000');
});
