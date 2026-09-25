<?php

declare(strict_types=1);

use App\Application\Claims\ClaimPaymentService;
use App\Application\Claims\Settlement\ClaimSettlementService;
use App\Application\Claims\Settlement\SettlementCalculator;
use App\Application\Documents\Signatures\SignatureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c13User(): User
{
    return User::create(['full_name' => 'Clm '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function c13Staff(Tenant $t, array $perms): User
{
    $u = c13User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLAIMS_MANAGER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'CLM_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Approved claim (decision 500 000), policy version with PER_CLAIM 400 000 + DEDUCTIBLE 25 000, one prior PAID payment of 50 000. */
function c13World(array $limits = [['PER_CLAIM', 400000], ['DEDUCTIBLE', 25000]]): array
{
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    app(TenantContext::class)->set($f['tenant']->id);
    $maker = c13User();
    $checker = c13User();
    $policy = (string) Str::uuid();
    DB::table('policies')->insert([
        'id' => $policy, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01 00:00:00+00', 'coverage_ends_at' => '2027-01-01 00:00:00+00', 'issued_at' => '2026-01-01 00:00:00+00',
        'policy_number' => 'POL-'.Str::random(6), 'terms_snapshot' => json_encode(['line_code' => 'AUTO']), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'is_demo' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $version = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $version, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => '2026-01-01 00:00:00+00', 'recorded_at' => '2026-01-01 00:00:00+00', 'schema_version' => 1, 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64)]);
    foreach ($limits as $l) {
        DB::table('policy_limits')->insert(['id' => (string) Str::uuid(), 'policy_id' => $policy, 'policy_version_id' => $version, 'limit_type' => $l[0],
            'amount_minor' => $l[1], 'consumed_minor' => $l[2] ?? 0, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);
    }
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'APPROVED', 'loss_occurred_at' => '2026-03-01', 'loss_details' => '{}', 'submitted_at' => now(), 'created_at' => now(), 'currency' => 'XAF', 'priority' => 'NORMAL',
        'current_reserve_minor' => 500000, 'approved_amount_minor' => 500000, 'version' => 1, 'is_demo' => false]);
    $decision = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decision, 'claim_id' => $claim, 'decision' => 'APPROVE', 'approved_amount_minor' => 500000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'ok', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()]);
    DB::table('claim_payments')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim, 'claim_decision_id' => $decision, 'payee_party_id' => $f['party']->id,
        'amount_minor' => 50000, 'currency' => 'XAF', 'status' => 'PAID', 'idempotency_key' => Str::uuid(), 'attempt_count' => 1, 'requested_by' => $maker->id, 'approved_by' => $checker->id, 'paid_at' => now()]);

    return $f + ['maker' => $maker, 'checker' => $checker, 'claim' => Claim::findOrFail($claim), 'policy' => $policy];
}

it('REQ-CLM-013 calculates covered − excluded − deductible − prior ± adjustments with an explainable breakdown', function () {
    $r = (new SettlementCalculator)->calculate(600000, 30000, 25000, 50000, [['code' => 'SALVAGE', 'amount_minor' => -15000], ['code' => 'INTEREST', 'amount_minor' => 5000]], null);
    expect($r['gross_minor'])->toBe(485000)->and($r['amount_minor'])->toBe(485000)->and($r['adjustments_minor'])->toBe(-10000)->and($r['limit_capped'])->toBeFalse();
    expect(array_column($r['lines'], 'code'))->toBe(['COVERED', 'EXCLUDED', 'DEDUCTIBLE', 'PRIOR_PAYMENTS', 'ADJUSTMENT', 'ADJUSTMENT', 'SETTLEMENT']);
    expect(end($r['lines'])['running_minor'])->toBe(485000);

    $capped = (new SettlementCalculator)->calculate(600000, 0, 0, 0, [], 200000);
    expect($capped['amount_minor'])->toBe(200000)->and($capped['limit_capped'])->toBeTrue();
    expect((new SettlementCalculator)->calculate(10000, 20000, 0, 0, [], null)['amount_minor'])->toBe(0);
    expect(fn () => (new SettlementCalculator)->calculate(-1, 0, 0, 0, [], null))->toThrow(ValidationException::class);
});

it('REQ-CLM-013 caps at the remaining policy limit at the loss date and defaults the deductible from policy_limits', function () {
    $w = c13World();
    $s = app(ClaimSettlementService::class)->calculate($w['claim'], ['covered_minor' => 600000, 'excluded_minor' => 30000, 'adjustments' => [['code' => 'OTHER', 'amount_minor' => 10000]]], $w['maker']);
    expect($s->status)->toBe('CALCULATED')->and((int) $s->deductible_minor)->toBe(25000)->and((int) $s->prior_payments_minor)->toBe(50000)
        ->and((int) $s->gross_minor)->toBe(505000)->and((int) $s->remaining_limit_minor)->toBe(350000)->and((int) $s->amount_minor)->toBe(350000)
        ->and((bool) $s->limit_capped)->toBeTrue()->and($s->limit_source)->toBe('POLICY_LIMITS');
    $b = json_decode($s->breakdown, true);
    expect($b['deductible_source'])->toBe('POLICY')->and(collect($b['lines'])->pluck('code')->all())->toContain('LIMIT_CAP');

    // Aggregate consumption tightens the cap; recalculating supersedes the previous live settlement.
    DB::table('policy_limits')->insert(['id' => (string) Str::uuid(), 'policy_id' => $w['policy'], 'policy_version_id' => DB::table('policy_versions')->where('policy_id', $w['policy'])->value('id'),
        'limit_type' => 'AGGREGATE', 'amount_minor' => 1000000, 'consumed_minor' => 900000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);
    $s2 = app(ClaimSettlementService::class)->calculate($w['claim'], ['covered_minor' => 600000, 'deductible_minor' => 0], $w['maker']);
    expect((int) $s2->amount_minor)->toBe(100000)->and(DB::table('claim_settlements')->where('id', $s->id)->value('status'))->toBe('SUPERSEDED');
    expect(DB::table('outbox_messages')->where('event_name', 'claim.settlement.calculated')->count())->toBe(2);
});

it('REQ-CLM-013 runs CALCULATED → OFFERED → ACCEPTED → DISCHARGE_SIGNED → PAYMENT_PENDING → PAID with obligation and postings', function () {
    $w = c13World();
    $svc = app(ClaimSettlementService::class);
    $s = $svc->calculate($w['claim'], ['covered_minor' => 300000, 'excluded_minor' => 20000], $w['maker']);
    expect((int) $s->amount_minor)->toBe(205000); // 300000 − 20000 − 25000 − 50000

    expect(fn () => $svc->offer($s->id, $w['maker']))->toThrow(ValidationException::class); // four eyes
    expect(fn () => $svc->accept($s->id, $w['user']))->toThrow(ValidationException::class); // not offered yet
    $svc->offer($s->id, $w['checker']);
    $s = $svc->accept($s->id, $w['user']);
    expect($s->status)->toBe('ACCEPTED');

    expect(fn () => $svc->confirmDischarge($s->id, $w['maker']))->toThrow(ValidationException::class);
    $s = $svc->requestDischarge($s->id, $w['maker']);
    $doc = DB::table('documents')->find($s->discharge_document_id);
    expect($doc->document_type_code)->toBe('DISCHARGE')->and($doc->status)->toBe('PENDING_SIGNATURE')->and($doc->claim_id)->toBe($w['claim']->id);
    expect($svc->requestDischarge($s->id, $w['maker'])->signature_request_id)->toBe($s->signature_request_id); // idempotent

    app(SignatureService::class)->sign($s->signature_request_id, $w['user'], ['ip' => '127.0.0.1', 'consent_accepted' => true]);
    $s = $svc->confirmDischarge($s->id, $w['user']);
    expect($s->status)->toBe('DISCHARGE_SIGNED')->and(DB::table('documents')->where('id', $doc->id)->value('status'))->toBe('ISSUED');

    $s = $svc->requestPayment($s->id, $w['maker']);
    expect($s->status)->toBe('PAYMENT_PENDING');
    $ob = DB::table('financial_obligations')->find($s->financial_obligation_id);
    expect($ob->kind)->toBe('PAYABLE')->and($ob->type)->toBe('CLAIM')->and($ob->creditor_type)->toBe('PARTY')->and($ob->creditor_id)->toBe($w['party']->id)
        ->and((int) $ob->amount_minor)->toBe(205000)->and($ob->status)->toBe('OPEN');
    expect(DB::table('journals')->where(['reference_type' => 'claim.settlement.approved', 'reference_id' => $s->id, 'status' => 'POSTED'])->exists())->toBeTrue();

    $pay = app(ClaimPaymentService::class);
    $p = ClaimPayment::findOrFail($s->claim_payment_id);
    expect($p->claim_settlement_id)->toBe($s->id)->and($p->amount_minor)->toBe(205000);
    $p = $pay->approve($p, $w['checker']);
    $p = $pay->processing($p);
    $pay->paid($p, 'MOMO-123');

    $s = $svc->get($s->id);
    expect($s->status)->toBe('PAID')->and($s->paid_journal_id)->not->toBeNull();
    expect(DB::table('financial_obligations')->where('id', $s->financial_obligation_id)->value('status'))->toBe('SETTLED');
    expect(DB::table('journals')->where(['reference_type' => 'claim.settlement.paid', 'reference_id' => $s->id])->exists())->toBeTrue();
    expect(Claim::find($w['claim']->id)->status)->toBe('PAID');
    expect(DB::table('claim_settlement_events')->where('claim_settlement_id', $s->id)->pluck('to_status')->all())
        ->toBe(['CALCULATED', 'OFFERED', 'ACCEPTED', 'ACCEPTED', 'DISCHARGE_SIGNED', 'PAYMENT_PENDING', 'PAID']);
    expect(DB::table('outbox_messages')->where('event_name', 'claim.settlement.paid')->exists())->toBeTrue();
});

it('REQ-CLM-013 a disputed offer can only be superseded by a recalculation; payment needs a signed discharge', function () {
    $w = c13World([]);
    $svc = app(ClaimSettlementService::class);
    $s = $svc->calculate($w['claim'], ['covered_minor' => 100000], $w['maker']);
    expect($s->limit_source)->toBe('NONE')->and($s->remaining_limit_minor)->toBeNull()->and((int) $s->amount_minor)->toBe(50000);
    $svc->offer($s->id, $w['checker']);
    $d = $svc->dispute($s->id, 'Garage invoice ignored', $w['user']);
    expect($d->status)->toBe('DISPUTED')->and($d->dispute_reason)->toBe('Garage invoice ignored');
    expect(fn () => $svc->accept($s->id, $w['user']))->toThrow(ValidationException::class);
    expect(fn () => $svc->requestPayment($s->id, $w['maker']))->toThrow(ValidationException::class);
    $n = $svc->calculate($w['claim'], ['covered_minor' => 120000], $w['maker']);
    expect((int) $n->amount_minor)->toBe(70000)->and($svc->get($s->id)->status)->toBe('SUPERSEDED');

    // An offer above the approved decision is refused.
    DB::table('claim_decisions')->where('claim_id', $w['claim']->id)->update(['approved_amount_minor' => 60000]);
    expect(fn () => $svc->offer($n->id, $w['checker']))->toThrow(ValidationException::class);
});

it('REQ-CLM-013 exposes the settlement API with permissions and tenant isolation', function () {
    $w = c13World();
    $staff = c13Staff($w['tenant'], ['claims.settlement.calculate', 'claims.view']);
    Passport::actingAs($staff);
    $res = $this->postJson("/api/v1/claims/{$w['claim']->id}/settlements", ['covered_minor' => 200000, 'adjustments' => [['code' => 'FEE', 'amount_minor' => 5000, 'reason' => 'towing']]], tenantHeaderFor($w['tenant']))
        ->assertCreated()->assertJsonPath('data.amount_minor', 130000)->assertJsonPath('data.status', 'CALCULATED');
    $id = $res->json('data.id');
    $this->getJson("/api/v1/claim-settlements/{$id}", tenantHeaderFor($w['tenant']))->assertOk()->assertJsonPath('data.breakdown.lines.0.code', 'COVERED')->assertJsonCount(1, 'data.history');
    $this->postJson("/api/v1/claim-settlements/{$id}/offer", [], tenantHeaderFor($w['tenant']))->assertForbidden();

    $other = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $stranger = c13Staff($other['tenant'], ['claims.view']);
    Passport::actingAs($stranger);
    $this->getJson("/api/v1/claim-settlements/{$id}", tenantHeaderFor($other['tenant']))->assertNotFound();
});
