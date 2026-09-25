<?php

declare(strict_types=1);

/**
 * Agent C16 — REQ-FRD-001 claim fraud indicators + WF-089 suspicious-claim review (LOCK-010),
 * REQ-FRD-002 cash-fraud segregation of duties (collect / reconcile / refund) + SoD violation report.
 */

use App\Application\Cases\Models\WorkCase;
use App\Application\Finance\Cashier\CashierSessionService;
use App\Application\Finance\Refunds\RefundEngine;
use App\Application\Fraud\ClaimFraudHold;
use App\Application\Fraud\ClaimFraudIndicatorService;
use App\Application\Fraud\RuleConditionEvaluator;
use App\Application\Fraud\SegregationOfDutiesPolicy;
use App\Application\Reconciliation\ManualMatchService;
use App\Models\Claim;
use App\Models\FraudRuleVersion;
use App\Models\ReconciliationItem;
use App\Models\RiskAlert;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function c16Rule(string $code, array $conditions, int $points = 40, int $version = 1, string $status = 'ACTIVE'): FraudRuleVersion
{
    return FraudRuleVersion::create(['code' => $code, 'version' => $version, 'scope' => 'CLAIM', 'status' => $status, 'risk_points' => $points,
        'conditions' => $conditions, 'rule_hash' => hash('sha256', json_encode($conditions)), 'effective_from' => now()->subYear()->toDateString()]);
}

/** Policy started 10 days before the loss, claim of 900 000 on a 36 500 premium. */
function c16Claim(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = (string) Str::uuid();
    $start = now()->subDays(20);
    DB::table('policies')->insert([
        'id' => $policy, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => 'ACTIVE', 'coverage_starts_at' => $start, 'coverage_ends_at' => $start->copy()->addYear(), 'issued_at' => $start,
        'terms_snapshot' => '{}', 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 36500, 'is_demo' => false, 'created_at' => $start, 'updated_at' => $start,
    ]);
    $id = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy, 'claimant_party_id' => $f['party']->id,
        'claim_number' => 'CLM-'.Str::random(8), 'status' => 'CARRIER_REVIEW', 'loss_occurred_at' => now()->subDays(10), 'loss_details' => '{}',
        'submitted_at' => now()->subDays(1), 'estimated_loss_minor' => 900000, 'currency' => 'XAF', 'priority' => 'NORMAL', 'version' => 1, 'is_demo' => false,
        'created_at' => now(), 'updated_at' => now()]);

    return $f + ['claim' => Claim::findOrFail($id)];
}

it('REQ-FRD-001 evaluates versioned rule conditions and explains why each fired', function () {
    $e = new RuleConditionEvaluator;
    $facts = ['days_inception_to_loss' => 10, 'loss_to_premium_ratio' => 24.6, 'prior_claims_365d' => null];
    $r = $e->evaluate(['op' => 'all', 'conditions' => [['op' => 'lt', 'fact' => 'days_inception_to_loss', 'value' => 30], ['op' => 'gte', 'fact' => 'loss_to_premium_ratio', 'value' => 10]]], $facts);
    expect($r['matched'])->toBeTrue()->and($r['reasons'])->toBe(['days_inception_to_loss = 10 < 30', 'loss_to_premium_ratio = 24.6 >= 10']);
    expect($e->evaluate(['op' => 'gte', 'fact' => 'prior_claims_365d', 'value' => 0], $facts)['matched'])->toBeFalse(); // missing data never fires
    expect($e->evaluate(['op' => 'bogus', 'fact' => 'days_inception_to_loss', 'value' => 1], $facts)['matched'])->toBeFalse();
    expect($e->evaluate(['op' => 'any', 'conditions' => [['op' => 'gt', 'fact' => 'days_inception_to_loss', 'value' => 99], ['op' => 'in', 'fact' => 'days_inception_to_loss', 'value' => [10, 11]]]], $facts)['matched'])->toBeTrue();
});

it('REQ-FRD-001 / LOCK-010 indicators put the claim under REVIEW_REQUIRED with a suspicious-claim case — never FRAUD_CONFIRMED', function () {
    $w = c16Claim();
    c16Rule('EARLY_LOSS', ['op' => 'lt', 'fact' => 'days_inception_to_loss', 'value' => 30], 40, 1, 'RETIRED');
    $early = c16Rule('EARLY_LOSS', ['op' => 'lt', 'fact' => 'days_inception_to_loss', 'value' => 30], 40, 2);
    c16Rule('HIGH_RATIO', ['op' => 'gte', 'fact' => 'loss_to_premium_ratio', 'value' => 10], 30);
    c16Rule('NOT_FIRING', ['op' => 'gt', 'fact' => 'prior_claims_365d', 'value' => 3], 50);
    $actor = makeAuthTestUser($w['tenant'], ['fraud.alert.create']);

    $res = app(ClaimFraudIndicatorService::class)->assess($w['claim'], $actor);
    expect(array_column($res['indicators'], 'code'))->toBe(['EARLY_LOSS', 'HIGH_RATIO'])
        ->and($res['indicators'][0]['version'])->toBe(2)->and($res['indicators'][0]['rule_id'])->toBe($early->id)
        ->and($res['indicators'][0]['reasons'][0])->toBe('days_inception_to_loss = 10 < 30');
    $alert = $res['review'];
    expect($alert->status)->toBe('OPEN')->and($alert->risk_score)->toBe(70)->and($alert->severity)->toBe('HIGH')
        ->and($alert->signals['automated_outcome'])->toBe('REVIEW_REQUIRED');
    $claim = DB::table('claims')->find($w['claim']->id);
    expect($claim->fraud_flag)->toBe('REVIEW_REQUIRED')->and($claim->fraud_flag_set_by)->toBeNull()->and($claim->status)->toBe('CARRIER_REVIEW');
    $case = WorkCase::withoutGlobalScopes()->findOrFail($res['case_id']);
    expect($case->case_type_code)->toBe('CLAIM_INVESTIGATION')->and($case->subject_id)->toBe($w['claim']->id)->and($case->source_id)->toBe($alert->id);
    expect(DB::table('outbox_messages')->where('event_name', 'fraud.claim.review_required')->count())->toBe(1);

    // Idempotent while open.
    expect(app(ClaimFraudIndicatorService::class)->assess($w['claim'], $actor)['review']->id)->toBe($alert->id);
    expect(RiskAlert::where('subject_id', $w['claim']->id)->count())->toBe(1);

    // The database refuses an automated (no human) FRAUD_CONFIRMED.
    expect(fn () => DB::table('claims')->where('id', $w['claim']->id)->update(['fraud_flag' => 'FRAUD_CONFIRMED', 'fraud_flag_set_by' => null]))->toThrow(QueryException::class);
});

it('REQ-FRD-001 no indicator means no review', function () {
    $w = c16Claim();
    c16Rule('NOT_FIRING', ['op' => 'gt', 'fact' => 'prior_claims_365d', 'value' => 3], 50);
    $res = app(ClaimFraudIndicatorService::class)->assess($w['claim'], null);
    expect($res['review'])->toBeNull()->and(DB::table('claims')->where('id', $w['claim']->id)->value('fraud_flag'))->toBeNull();
});

it('REQ-FRD-001 / WF-089 blocks approval and settlement while the review is open; a human records the outcome through the case engine', function () {
    $w = c16Claim();
    c16Rule('EARLY_LOSS', ['op' => 'lt', 'fact' => 'days_inception_to_loss', 'value' => 30], 40);
    $hold = app(ClaimFraudHold::class);
    expect($hold->check($w['claim'], 'approve', ['to' => 'APPROVED']))->toBeNull();

    $assessor = makeAuthTestUser($w['tenant'], ['fraud.alert.create']);
    $reviewer = makeAuthTestUser($w['tenant'], ['fraud.alert.decide']);
    $h = tenantHeaderFor($w['tenant']);
    Passport::actingAs($assessor);
    $alertId = $this->postJson("/api/v1/fraud/claims/{$w['claim']->id}/assess", [], $h)->assertOk()
        ->assertJsonPath('data.fraud_flag', 'REVIEW_REQUIRED')->assertJsonPath('data.indicators.0.code', 'EARLY_LOSS')->json('data.risk_alert_id');

    foreach ([['approve', ['to' => 'APPROVED']], ['partially_approve', ['to' => 'PARTIALLY_APPROVED']], ['request_settlement', ['to' => 'SETTLEMENT_PENDING']], ['settle', []]] as [$ev, $ctx]) {
        expect($hold->check($w['claim'], $ev, $ctx))->toBe('FRAUD_REVIEW_OPEN');
    }
    expect($hold->check($w['claim'], 'decline', ['to' => 'REJECTED']))->toBeNull()
        ->and($hold->check($w['claim'], 'investigate', ['to' => 'INVESTIGATING']))->toBeNull();
    $guard = collect(iterator_to_array(app()->tagged('claims.transition_guards')))->first(fn ($g) => $g instanceof \App\Application\Fraud\FraudReviewClaimTransitionGuard);
    expect($guard)->not->toBeNull()->and($guard->events())->toBe(['approve', 'partially_approve', 'request_settlement', 'settle'])
        ->and($guard->check($w['claim'], 'settle', ['to' => 'SETTLED']))->toBe('FRAUD_REVIEW_OPEN');

    $this->postJson("/api/v1/fraud/claim-reviews/{$alertId}/outcome", ['outcome' => 'CLEARED', 'rationale' => 'Checked documents and police report.'], $h)->assertForbidden();
    Passport::actingAs($reviewer);
    $this->postJson("/api/v1/fraud/claim-reviews/{$alertId}/outcome", ['outcome' => 'CLEARED', 'rationale' => 'short'], $h)->assertStatus(422);
    $this->postJson("/api/v1/fraud/claim-reviews/{$alertId}/outcome", ['outcome' => 'CLEARED', 'rationale' => 'Checked documents, garage invoice and police report.'], $h)
        ->assertOk()->assertJsonPath('data.status', 'DECIDED')->assertJsonPath('data.fraud_flag', 'CLEARED');

    $alert = RiskAlert::findOrFail($alertId);
    expect($alert->decided_by)->toBe($reviewer->id);
    $decision = DB::table('case_decisions')->where('case_id', $alert->case_id)->sole();
    expect($decision->decision_type)->toBe('FRAUD_REVIEW')->and($decision->outcome)->toBe('CLEARED')->and($decision->decided_by)->toBe($reviewer->id);
    expect(DB::table('claims')->where('id', $w['claim']->id)->value('fraud_flag_set_by'))->toBe($reviewer->id);
    expect($hold->check($w['claim'], 'approve', ['to' => 'APPROVED']))->toBeNull();
    $this->postJson("/api/v1/fraud/claim-reviews/{$alertId}/outcome", ['outcome' => 'CLEARED', 'rationale' => 'Checked documents, garage invoice and police report.'], $h)->assertStatus(422);
});

it('REQ-FRD-001 a human-confirmed fraud keeps approval blocked', function () {
    $w = c16Claim();
    c16Rule('EARLY_LOSS', ['op' => 'lt', 'fact' => 'days_inception_to_loss', 'value' => 30], 40);
    $svc = app(ClaimFraudIndicatorService::class);
    $alert = $svc->assess($w['claim'], null)['review'];
    $svc->recordOutcome($alert, 'CONFIRMED_FRAUD', 'Staged accident confirmed by the investigator on site.', makeAuthTestUser($w['tenant'], []));
    expect(DB::table('claims')->where('id', $w['claim']->id)->value('fraud_flag'))->toBe('FRAUD_CONFIRMED')
        ->and(app(ClaimFraudHold::class)->check($w['claim'], 'approve', ['to' => 'APPROVED']))->toBe('FRAUD_CONFIRMED');
});

function c16Money(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'requested_by' => $f['user']->id]);
    $f['branch'] = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $f['branch'], 'tenant_id' => $f['tenant']->id, 'code' => 'BR-'.Str::random(5), 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

function c16Collect(array $f, $cashier): void
{
    $svc = app(CashierSessionService::class);
    $s = $svc->open($f['tenant']->id, $f['branch'], 0, 'XAF', $cashier);
    $svc->collect($f['tenant']->id, $s->id, ['method' => 'CASH', 'amount_minor' => 100000, 'payer_name' => 'Jean', 'payment_intent_id' => $f['payment']->id], $cashier);
}

function c16ExceptionItem(array $f): ReconciliationItem
{
    $imp = (string) Str::uuid();
    DB::table('reconciliation_imports')->insert(['id' => $imp, 'tenant_id' => $f['tenant']->id, 'source_type' => 'BANK', 'provider' => 'bank', 'statement_reference' => 'ST-'.Str::random(5),
        'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF', 'status' => 'COMPLETED', 'file_hash' => hash('sha256', Str::random(9)), 'uploaded_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    $id = (string) Str::uuid();
    DB::table('reconciliation_items')->insert(['id' => $id, 'reconciliation_import_id' => $imp, 'external_reference' => 'X-'.Str::random(6), 'transaction_at' => now(),
        'gross_minor' => 100000, 'fee_minor' => 0, 'net_minor' => 100000, 'currency' => 'XAF', 'status' => 'EXCEPTION', 'created_at' => now(), 'updated_at' => now()]);

    return ReconciliationItem::findOrFail($id);
}

it('REQ-FRD-002 the collector of cash may not reconcile or refund the same money', function () {
    $f = c16Money();
    $cashier = makeAuthTestUser($f['tenant'], []);
    $other = makeAuthTestUser($f['tenant'], []);
    c16Collect($f, $cashier);
    $sod = app(SegregationOfDutiesPolicy::class);
    expect($sod->dutiesOf($f['payment']->id, $cashier->id))->toBe(['COLLECT'])
        ->and($sod->conflict('RECONCILE', $f['payment']->id, $cashier->id))->toBe('COLLECT')
        ->and($sod->conflict('RECONCILE', $f['payment']->id, $other->id))->toBeNull();

    $item = c16ExceptionItem($f);
    $mm = app(ManualMatchService::class);
    expect(fn () => $mm->request($f['tenant']->id, $item, ['matched_id' => $f['payment']->id, 'notes' => 'match'], $cashier))
        ->toThrow(ValidationException::class, 'SOD_VIOLATION');
    $m = $mm->request($f['tenant']->id, $item, ['matched_id' => $f['payment']->id, 'notes' => 'match'], $other);
    expect(fn () => $mm->decide($f['tenant']->id, $m->id, true, 'ok', $cashier))->toThrow(ValidationException::class, 'SOD_VIOLATION');

    $engine = app(RefundEngine::class);
    $refund = $engine->candidate($f['payment'], 'manual', (string) Str::uuid(), 'CUSTOMER_REQUEST', $other);
    expect(fn () => $engine->calculate($refund, [], $cashier))->toThrow(ValidationException::class, 'SOD_VIOLATION');
    // The reconciler (maker of the manual match) may not handle the refund either.
    expect(fn () => $engine->calculate($refund, [], $other))->toThrow(ValidationException::class, 'SOD_VIOLATION');
    $third = makeAuthTestUser($f['tenant'], []);
    expect($engine->calculate($refund, [], $third)->status)->toBe('CALCULATED');

    // Nobody who refunded or reconciled the payment may then collect cash against it.
    $svc = app(CashierSessionService::class);
    $s = $svc->open($f['tenant']->id, $f['branch'], 0, 'XAF', $third);
    expect(fn () => $svc->collect($f['tenant']->id, $s->id, ['method' => 'CASH', 'amount_minor' => 100, 'payer_name' => 'J', 'payment_intent_id' => $f['payment']->id], $third))
        ->toThrow(ValidationException::class, 'SOD_VIOLATION');
    expect(DB::table('audit_events')->where('action', 'fraud.sod.blocked')->count())->toBeGreaterThan(0);
});

it('REQ-FRD-002 reports historical SoD violations per payment and user', function () {
    $f = c16Money();
    $cashier = makeAuthTestUser($f['tenant'], ['fraud.sod.report']);
    c16Collect($f, $cashier);
    $refund = app(RefundEngine::class)->candidate($f['payment'], 'manual', (string) Str::uuid(), 'CUSTOMER_REQUEST', $cashier);
    // Legacy data written before the control existed.
    DB::table('refunds')->where('id', $refund->id)->update(['calculated_by' => $cashier->id]);

    $h = tenantHeaderFor($f['tenant']);
    Passport::actingAs(makeAuthTestUser($f['tenant'], []));
    $this->getJson('/api/v1/fraud/sod-violations', $h)->assertForbidden();
    Passport::actingAs($cashier);
    $this->getJson('/api/v1/fraud/sod-violations', $h)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payment_intent_id', $f['payment']->id)->assertJsonPath('data.0.user_id', $cashier->id)
        ->assertJsonPath('data.0.duties', ['COLLECT', 'REFUND']);
});
