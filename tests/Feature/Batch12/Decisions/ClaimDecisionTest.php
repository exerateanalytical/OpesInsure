<?php

declare(strict_types=1);

use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c12Staff(Tenant $t, array $perms, ?int $limit, string $carrierId): User
{
    $u = User::create(['full_name' => 'Claims '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLAIMS_OFFICER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'C12_'.Str::random(5), 'permissions' => $perms, 'is_system' => false])->id);
    if ($limit !== null) {
        DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'holder_type' => 'USER', 'holder_id' => $u->id,
            'authority_type' => 'CLAIM_SETTLE', 'max_amount_minor' => $limit, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $u;
}

function c12World(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => 'CARRIER_REVIEW', 'estimated_loss_minor' => 1_000_000]);
    app(TenantContext::class)->set($f['tenant']->id);
    $perms = ['claims.view', 'claims.decision.propose', 'claims.decision.approve', 'claims.decision.appeal'];

    return $f + ['policy' => $policy, 'claim' => $claim,
        'maker' => c12Staff($f['tenant'], $perms, 500_000, $f['carrier']->id),
        'checker' => c12Staff($f['tenant'], $perms, 500_000, $f['carrier']->id),
        'supervisor' => c12Staff($f['tenant'], [...$perms, 'claims.decision.supervise'], 5_000_000, $f['carrier']->id)];
}

function c12Propose(array $w, string $decision, array $heads, array $reasons, ?User $by = null): ClaimDecision
{
    return app(ClaimDecisionService::class)->propose($w['claim'], ['decision' => $decision, 'reason_codes' => $reasons,
        'rationale' => 'Assessed against the adjuster report and policy wording.', 'heads' => $heads], $by ?? $w['maker']);
}

it('REQ-CLM-012 approves a partial decision within authority under maker-checker, per head, and notifies the customer with reasons', function () {
    $w = c12World();
    $d = c12Propose($w, 'PARTIAL', [['head' => 'REPAIR', 'amount_minor' => 300_000], ['head' => 'MEDICAL', 'amount_minor' => 50_000]], ['DEDUCTIBLE_APPLIED', 'DEPRECIATION_APPLIED']);
    expect($d->status)->toBe('PENDING_APPROVAL')->and($d->approved_amount_minor)->toBe(350_000)->and($d->reason_code)->toBe('DEDUCTIBLE_APPLIED')
        ->and(DB::table('authority_checks')->where('id', $d->authority_check_id)->value('outcome'))->toBe('ALLOWED');

    expect(fn () => app(ClaimDecisionService::class)->approve($d, $w['maker']))->toThrow(ValidationException::class);

    $d = app(ClaimDecisionService::class)->approve($d, $w['checker']);
    $claim = $w['claim']->refresh();
    expect($d->status)->toBe('APPROVED')->and($claim->status)->toBe('PARTIALLY_APPROVED')->and((int) $claim->approved_amount_minor)->toBe(350_000)
        ->and($d->notification_status)->toBe('QUEUED');
    $n = DB::table('notification_deliveries')->where('id', $d->notification_delivery_id)->first();
    expect(json_decode($n->payload, true)['reasons'])->toContain('deductible')->and($n->party_id)->toBe($w['party']->id);
    expect(DB::table('claim_events')->where(['claim_id' => $claim->id, 'to_status' => 'PARTIALLY_APPROVED'])->exists())->toBeTrue();
    expect(DB::table('outbox_messages')->whereIn('event_name', ['claim.decision.proposed', 'claim.decision.approved', 'claim.decision.notified'])->count())->toBe(3);
});

it('REQ-CLM-012 validates reason codes against the catalogue and heads against the decision', function () {
    $w = c12World();
    expect(fn () => c12Propose($w, 'DECLINE', [], ['DEDUCTIBLE_APPLIED']))->toThrow(ValidationException::class);
    expect(fn () => c12Propose($w, 'APPROVE', [['head' => 'REPAIR', 'amount_minor' => 1000]], ['MADE_UP']))->toThrow(ValidationException::class);
    expect(fn () => c12Propose($w, 'DECLINE', [['head' => 'REPAIR', 'amount_minor' => 1000]], ['NOT_COVERED']))->toThrow(ValidationException::class);
    expect(fn () => c12Propose($w, 'APPROVE', [], ['COVERED_IN_FULL']))->toThrow(ValidationException::class);
    expect(fn () => c12Propose($w, 'PARTIAL', [['head' => 'REPAIR', 'amount_minor' => 1_000_000]], ['SUB_LIMIT_APPLIED']))->toThrow(ValidationException::class);
    expect(ClaimDecision::count())->toBe(0);
});

it('REQ-CLM-012 refers a decision over CLAIM_SETTLE authority to a case and needs a supervisor', function () {
    $w = c12World();
    $d = c12Propose($w, 'APPROVE', [['head' => 'INDEMNITY', 'amount_minor' => 900_000]], ['COVERED_IN_FULL']);
    expect($d->status)->toBe('REFERRED')->and($d->referral_case_id)->not->toBeNull()
        ->and(DB::table('cases')->where('id', $d->referral_case_id)->value('case_type_code'))->toBe('AUTHORITY_REFERRAL')
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.decision.referred')->exists())->toBeTrue();

    expect(fn () => app(ClaimDecisionService::class)->approve($d, $w['checker']))->toThrow(ValidationException::class);
    expect($w['claim']->refresh()->status)->toBe('CARRIER_REVIEW');

    $d = app(ClaimDecisionService::class)->approve($d, $w['supervisor']);
    expect($d->status)->toBe('APPROVED')->and($d->approved_by)->toBe($w['supervisor']->id)
        ->and($d->authority_snapshot['checker']['outcome'])->toBe('ALLOWED')
        ->and($w['claim']->refresh()->status)->toBe('APPROVED');
});

it('REQ-CLM-012 lets the checker return a proposal without moving the claim', function () {
    $w = c12World();
    $d = c12Propose($w, 'DECLINE', [], ['EXCLUSION_APPLIES']);
    $d = app(ClaimDecisionService::class)->returnToMaker($d, 'Cite the exclusion clause.', $w['checker']);
    expect($d->status)->toBe('RETURNED')->and($w['claim']->refresh()->status)->toBe('CARRIER_REVIEW');
    expect(c12Propose($w, 'DECLINE', [], ['EXCLUSION_APPLIES', 'LATE_NOTIFICATION'])->status)->toBe('PENDING_APPROVAL');
});

it('REQ-CLM-012 keeps the original and the appeal decision (never overwritten)', function () {
    $w = c12World();
    $s = app(ClaimDecisionService::class);
    $original = $s->approve(c12Propose($w, 'DECLINE', [], ['NOT_COVERED']), $w['checker']);
    expect($w['claim']->refresh()->status)->toBe('DECLINED')
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.decision.rejected')->exists())->toBeTrue();

    $dispute = $s->appeal($w['claim'], 'NEW_EVIDENCE', 'Police report now attached shows theft.', $w['maker']);
    expect($w['claim']->refresh()->status)->toBe('DISPUTED');

    $appeal = c12Propose($w, 'APPROVE', [['head' => 'INDEMNITY', 'amount_minor' => 400_000]], ['APPEAL_UPHELD', 'COVERED_IN_FULL']);
    expect($appeal->kind)->toBe('APPEAL')->and($appeal->appeal_of_decision_id)->toBe($original->id)->and($appeal->dispute_id)->toBe($dispute->id);
    $appeal = $s->approve($appeal, $w['checker']);

    expect($w['claim']->refresh()->status)->toBe('APPROVED')->and(DB::table('claim_disputes')->where('id', $dispute->id)->value('status'))->toBe('RESOLVED');
    $history = $s->history($w['claim']);
    expect($history)->toHaveCount(2);
    $o = $original->refresh();
    expect($o->status)->toBe('APPROVED')->and($o->decision)->toBe('DECLINE')->and($o->reason_codes)->toBe(['NOT_COVERED']);

    expect(fn () => DB::table('claim_decisions')->where('id', $o->id)->update(['decision' => 'APPROVE']))->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-CLM-012 serves the decision API behind permissions', function () {
    $w = c12World();
    $h = ['X-Tenant-Id' => $w['tenant']->id];
    Passport::actingAs($w['maker']);
    $this->getJson('/api/v1/claim-decision-reason-codes', $h)->assertOk()->assertJsonFragment(['code' => 'NOT_COVERED']);
    $id = $this->postJson("/api/v1/claims/{$w['claim']->id}/decision-proposals", ['decision' => 'APPROVE', 'reason_codes' => ['COVERED_IN_FULL'],
        'rationale' => 'Covered peril, repair estimate validated.', 'heads' => [['head' => 'REPAIR', 'amount_minor' => 200_000]]], $h)
        ->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL')->json('data.id');
    $this->postJson("/api/v1/claims/{$w['claim']->id}/decision-proposals/{$id}/approve", [], $h)->assertUnprocessable();

    Passport::actingAs(c12Staff($w['tenant'], ['claims.view'], null, $w['carrier']->id));
    $this->postJson("/api/v1/claims/{$w['claim']->id}/decision-proposals/{$id}/approve", [], $h)->assertForbidden();

    Passport::actingAs($w['checker']);
    $this->postJson("/api/v1/claims/{$w['claim']->id}/decision-proposals/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->getJson("/api/v1/claims/{$w['claim']->id}/decision-history", $h)->assertOk()->assertJsonCount(1, 'data');
    expect(Claim::find($w['claim']->id)->status)->toBe('APPROVED');
});
