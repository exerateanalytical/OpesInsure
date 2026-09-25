<?php

declare(strict_types=1);

/**
 * REQ-UW-001 REQ-UW-002 REQ-UW-003 REQ-UW-004 REQ-UW-005 — Batch 7B underwriting: canonical case states + conditional
 * outcome, system evaluate step on the rules engine recording rule set versions (decision stays human), WF-019
 * information request loop with exact items, explainable risk score, UNDERWRITER / SENIOR_UNDERWRITER workspace.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\Models\RuleSet;
use App\Application\Underwriting\UnderwritingRecommendation;
use App\Domain\Rules\RuleDefinition;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

/** A customer's proposal submitted with a PRIOR_CLAIMS referral flag → underwriting case QUEUED (REFERRED). */
function b7bReferredProposal(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['proposal']->update(['status' => 'WITHDRAWN']);
    $quote = Quote::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'line_code' => 'AUTO', 'status' => 'ACCEPTED', 'currency' => 'XAF', 'risk_facts' => ['usage' => 'PRIVATE']]);
    $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id, 'tariff_version_id' => $f['tariff']->id,
        'premium_minor' => 90000, 'tax_minor' => 6000, 'fee_minor' => 4000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'ACCEPTED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
    $f['uw'] = makeAuthTestUser($f['tenant'], RoleCatalogue::defaultPermissions('UNDERWRITER'), 'UNDERWRITER');
    $f['senior'] = makeAuthTestUser($f['tenant'], RoleCatalogue::defaultPermissions('SENIOR_UNDERWRITER'), 'SENIOR_UNDERWRITER');

    $set = QuestionSet::create(['scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $f['product']->id, 'line_code' => 'AUTO', 'stage' => 'PROPOSAL', 'version' => 1,
        'status' => 'APPROVED', 'source' => 'MANUAL', 'schema_version' => 1, 'presentation' => ['steps' => [['key' => 'declarations', 'label' => 'Declarations']]],
        'schema_hash' => str_repeat('c', 64), 'effective_from' => '2026-01-01', 'approved_at' => now()]);
    $q = ['key' => 'prior_claims', 'label' => 'Claims in the last 3 years?', 'type' => 'boolean', 'required' => true, 'step' => 'declarations', 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS'];
    DB::table('product_questions')->insert(['id' => (string) Str::uuid(), 'question_set_id' => $set->id, 'code' => 'prior_claims', 'question_type' => 'BOOLEAN', 'input_type' => 'boolean',
        'label_en' => $q['label'], 'display_order' => 1, 'required' => true, 'validation' => '{}', 'fact_key' => 'prior_claims', 'rendered_field' => json_encode($q), 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);
    $id = test()->postJson('/api/v1/proposals', ['quote_offer_id' => $offer->id, 'party_id' => $f['party']->id], $h)->assertCreated()->json('data.id');
    test()->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => true]], $h)->assertOk();
    test()->postJson("/api/v1/proposals/{$id}/disclosures/attest", [], $h)->assertOk();
    test()->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(202)->assertJsonPath('data.status', 'UNDER_REVIEW');
    $f['proposal_id'] = $id;
    $f['case'] = UnderwritingCase::where('proposal_id', $id)->firstOrFail();
    $f['h'] = $h;

    return $f;
}

/** An APPROVED, effective UNDERWRITING-domain rule set for the product (rules engine REQ-RUL-002). */
function b7bRuleSet(string $productId, array $rules, int $version = 1): RuleSet
{
    $set = RuleSet::create(['code' => 'UW_MOTOR_TEST', 'domain' => 'UNDERWRITING', 'scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $productId, 'version' => $version,
        'status' => 'APPROVED', 'effective_from' => '2026-01-01', 'approved_at' => now()]);
    foreach (app(\App\Application\Rules\RuleSetService::class)->validateRules($rules, 'UNDERWRITING') as $r) {
        $set->rules()->create($r);
    }

    return $set->refresh();
}

it('REQ-UW-001 REQ-UW-002 REQ-UW-004 REQ-UW-005 walks the canonical case states, evaluates on versioned rules with an explainable score and records a human CONDITIONAL outcome', function () {
    $f = b7bReferredProposal('+237674100001');
    b7bRuleSet($f['product']->id, [
        ['code' => 'PRIOR_CLAIMS_FACTOR', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'proposal.answers.prior_claims'], 'right' => ['value' => true]],
            'outcome' => ['score' => ['factor' => 'CLAIMS_HISTORY', 'weight' => 30]]],
        ['code' => 'PRIVATE_USE_FACTOR', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'risk.usage'], 'right' => ['value' => 'COMMERCIAL']],
            'outcome' => ['score' => ['factor' => 'COMMERCIAL_USE', 'weight' => 25]]],
        ['code' => 'HIGH_SCORE_CONDITIONS', 'condition' => ['op' => 'GTE', 'left' => ['fact' => 'underwriting.risk_score'], 'right' => ['value' => 40]],
            'outcome' => ['result' => 'CONDITIONAL_ACCEPT', 'reason_code' => 'HIGH_RISK_SCORE', 'conditions' => [['code' => 'EXCESS_DOUBLED']]]],
    ]);
    $h = $f['h'];
    $caseId = $f['case']->id;

    // UW-005: customers have no access to the workspace; the UNDERWRITER role does.
    $this->getJson('/api/v1/underwriting/cases', $h)->assertForbidden();
    Passport::actingAs($f['uw']);
    $queue = $this->getJson('/api/v1/underwriting/cases', $h)->assertOk()->json('data');
    expect(collect($queue)->pluck('id')->all())->toContain($caseId)->and(collect($queue)->firstWhere('id', $caseId)['state'])->toBe('REFERRED');
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/assign", ['assignee_id' => $f['uw']->id], $h)->assertForbidden(); // assignment is senior-only

    // UW-002 / UW-004: system evaluate — recommendation only, rule set version recorded, score explained.
    $ev = $this->postJson("/api/v1/underwriting/cases/{$caseId}/evaluate", [], $h)->assertOk()->json('data');
    expect($ev['recommendation'])->toBe('REFER') // disclosure flag REFER outranks the rule's CONDITIONAL_ACCEPT
        ->and($ev['reasons'])->toContain('HIGH_RISK_SCORE', 'DISCLOSURE:PRIOR_CLAIMS')
        ->and($ev['conditions'])->toBe([['code' => 'EXCESS_DOUBLED']])
        ->and($ev['rule_set_versions'])->toBe(['rule_set:UW_MOTOR_TEST' => '1'])
        ->and($ev['risk_score'])->toBe(50)->and($ev['risk_band'])->toBe('MEDIUM')->and($ev['decision_is_human'])->toBeTrue();
    $factors = collect($ev['risk_factors'])->keyBy('factor');
    expect($factors['CLAIMS_HISTORY'])->toMatchArray(['weight' => 30, 'fired' => true, 'contribution' => 30, 'input' => ['proposal.answers.prior_claims' => true]])
        ->and($factors['COMMERCIAL_USE'])->toMatchArray(['weight' => 25, 'fired' => false, 'contribution' => 0, 'input' => ['risk.usage' => 'PRIVATE']])
        ->and($factors['DISCLOSURE_PRIOR_CLAIMS']['contribution'])->toBe(20);
    $log = DB::table('engine_evaluations')->where('id', $ev['engine_evaluation_id'])->first();
    expect($log->operation)->toBe('underwriting.evaluate')->and($log->subject_id)->toBe($caseId)->and($log->outcome)->toBe('REFER')
        ->and(json_decode($log->resolved_versions, true))->toHaveKey('rule_set:UW_MOTOR_TEST', '1');
    // Evaluate never decides.
    expect(UnderwritingDecision::where('underwriting_case_id', $caseId)->exists())->toBeFalse()->and(Proposal::find($f['proposal_id'])->status)->toBe('UNDER_REVIEW');

    // UW-001: REFERRED → ASSIGNED → REVIEWING → DECISION_PENDING → CONDITIONAL.
    Passport::actingAs($f['senior']);
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/assign", ['assignee_id' => $f['uw']->id], $h)->assertOk();
    Passport::actingAs($f['uw']);
    $this->getJson("/api/v1/underwriting/cases/{$caseId}", $h)->assertOk()->assertJsonPath('data.state', 'ASSIGNED')->assertJsonPath('data.risk_score', 50);
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/ready-for-decision", [], $h)->assertStatus(422); // not reviewing yet
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/start-review", [], $h)->assertOk()->assertJsonPath('data.state', 'REVIEWING');
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/ready-for-decision", [], $h)->assertStatus(422)->assertJsonValidationErrors('referrals');
    $referral = $f['case']->referrals()->firstOrFail();
    $this->postJson("/api/v1/underwriting/referrals/{$referral->id}/resolve", ['notes' => 'Claims history reviewed: one minor claim, acceptable.'], $h)->assertOk();
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/ready-for-decision", [], $h)->assertOk()->assertJsonPath('data.state', 'DECISION_PENDING');

    $this->postJson("/api/v1/underwriting/cases/{$caseId}/decision", ['decision' => 'CONDITIONAL', 'reason_code' => 'HIGH_RISK_SCORE', 'notes' => 'Accepted with doubled excess on own damage.'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('conditions');
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/decision", ['decision' => 'CONDITIONAL', 'reason_code' => 'HIGH_RISK_SCORE', 'notes' => 'Accepted with doubled excess on own damage.',
        'conditions' => ['items' => [['code' => 'EXCESS_DOUBLED']]]], $h)->assertOk()->assertJsonPath('data.status', 'PAYMENT_PENDING');

    $case = $f['case']->fresh();
    $decision = UnderwritingDecision::where('underwriting_case_id', $caseId)->firstOrFail();
    expect($case->status)->toBe('DECIDED')->and($case->outcome)->toBe('CONDITIONAL')
        ->and(\App\Application\Underwriting\UnderwritingCaseMachine::canonicalState($case))->toBe('CONDITIONAL')
        ->and($decision->decision)->toBe('APPROVED')->and($decision->outcome)->toBe('CONDITIONAL')
        ->and($decision->system_recommendation)->toBe('REFER')->and($decision->engine_evaluation_id)->toBe($ev['engine_evaluation_id'])
        ->and(DB::table('workflow_transition_history')->where('machine', 'proposal')->where('subject_id', $f['proposal_id'])->pluck('event')->last())->toBe('approve');
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/evaluate", [], $h)->assertStatus(422); // decided cases are closed
    $this->getJson("/api/v1/underwriting/cases/{$caseId}", $h)->assertOk()->assertJsonPath('data.decisions.0.outcome', 'CONDITIONAL')->assertJsonPath('data.available_events', []);
});

it('REQ-UW-003 REQ-UW-002 requests exact missing items from the originator and returns the resubmission to the requesting underwriter', function () {
    $f = b7bReferredProposal('+237674100002');
    b7bRuleSet($f['product']->id, [
        ['code' => 'VEHICLE_VALUE_CAP', 'condition' => ['op' => 'GT', 'left' => ['fact' => 'risk.vehicle_value_minor'], 'right' => ['value' => 5000000000]],
            'outcome' => ['result' => 'DECLINE', 'reason_code' => 'VALUE_ABOVE_CAPACITY']],
    ]);
    $h = $f['h'];
    $caseId = $f['case']->id;
    Passport::actingAs($f['uw']);
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/start-review", [], $h)->assertOk();

    $ev = $this->postJson("/api/v1/underwriting/cases/{$caseId}/evaluate", [], $h)->assertOk()->json('data');
    expect($ev['requested_items'])->toBe([['code' => 'RISK_VEHICLE_VALUE_MINOR', 'description' => 'Provide risk.vehicle_value_minor.']])
        ->and($ev['reasons'])->toContain('MORE_INFORMATION_REQUIRED:VEHICLE_VALUE_CAP');

    // Exact items are validated.
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/information-requests", ['items' => [['code' => 'bad code', 'description' => 'Something vague']]], $h)->assertStatus(422);
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/information-requests", ['items' => []], $h)->assertStatus(422);

    $this->postJson("/api/v1/underwriting/cases/{$caseId}/information-requests", ['use_evaluation_items' => true, 'message' => 'Please send the vehicle valuation.'], $h)
        ->assertOk()->assertJsonPath('data.state', 'INFORMATION_REQUESTED');
    $p = Proposal::find($f['proposal_id']);
    expect($p->status)->toBe('INFORMATION_REQUIRED')->and($p->information_request['items'][0]['code'])->toBe('RISK_VEHICLE_VALUE_MINOR')
        ->and($p->information_request['items'][0]['kind'])->toBe('CLARIFICATION');
    expect(DB::table('audit_log')->where('action', 'underwriting.information.requested')->where('subject_id', $caseId)->exists())->toBeTrue();

    // The originator answers → back to the underwriter who asked (REVIEWING, same assignee).
    Passport::actingAs($f['user']);
    $this->postJson("/api/v1/proposals/{$f['proposal_id']}/disclosures/attest", [], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$f['proposal_id']}/resubmit", ['response' => 'Valuation letter attached: 8,000,000 XAF.'], $h)->assertStatus(202);
    $case = $f['case']->fresh();
    expect($case->status)->toBe('IN_REVIEW')->and($case->assigned_to)->toBe($f['uw']->id)
        ->and(\App\Application\Underwriting\UnderwritingCaseMachine::canonicalState($case))->toBe('REVIEWING');

    Passport::actingAs($f['uw']);
    foreach ($case->referrals()->where('status', 'OPEN')->get() as $t) {
        $this->postJson("/api/v1/underwriting/referrals/{$t->id}/resolve", ['notes' => 'Reviewed the resubmitted information thoroughly.'], $h)->assertOk();
    }
    $this->postJson("/api/v1/underwriting/cases/{$caseId}/decision", ['decision' => 'DECLINED', 'reason_code' => 'RISK_TOO_HIGH', 'notes' => 'Risk outside appetite after review.'], $h)
        ->assertOk()->assertJsonPath('data.status', 'DECLINED');
    expect($case->fresh()->outcome)->toBe('DECLINED');
});

it('REQ-UW-001 keeps the mobile carrier referral decision working through the proposal machine', function () {
    $f = b7bReferredProposal('+237674100003');
    $carrierUser = makeAuthTestUser($f['tenant'], ['carrier.referrals.read', 'carrier.referrals.decide'], 'UNDERWRITER');
    Passport::actingAs($carrierUser);
    $this->postJson("/api/v1/mobile/carrier/referrals/{$f['case']->id}/decision", ['decision' => 'APPROVE', 'note' => 'Acceptable risk.'], $f['h'])->assertOk();
    expect(Proposal::find($f['proposal_id'])->status)->toBe('PAYMENT_PENDING')->and($f['case']->fresh()->outcome)->toBe('APPROVED');
});

it('REQ-UW-002 REQ-UW-004 combines recommendations by severity and bands the score', function () {
    $rule = fn (string $code, array $outcome) => new RuleDefinition($code, 100, false, true, $outcome);
    $fired = [
        ['rule' => $rule('A', ['result' => 'CONDITIONAL_ACCEPT', 'reason_code' => 'A']), 'unknown' => false, 'missing' => []],
        ['rule' => $rule('B', ['result' => 'MEDICAL', 'reason_code' => 'B', 'items' => [['code' => 'MEDICAL_REPORT', 'description' => 'Medical report']]]), 'unknown' => false, 'missing' => []],
        ['rule' => $rule('C', ['result' => 'SOMETHING_NEW']), 'unknown' => false, 'missing' => []],
    ];
    expect(UnderwritingRecommendation::combine([], [])['recommendation'])->toBe('AUTO_ACCEPT')
        ->and(UnderwritingRecommendation::combine(array_slice($fired, 0, 2), [])['recommendation'])->toBe('MEDICAL')
        ->and(UnderwritingRecommendation::combine(array_slice($fired, 0, 2), [])['requested_items'])->toBe([['code' => 'MEDICAL_REPORT', 'description' => 'Medical report']])
        ->and(UnderwritingRecommendation::combine($fired, [])['recommendation'])->toBe('REFER'); // unknown result never auto-accepts
    expect(UnderwritingRecommendation::band(0))->toBe('LOW')->and(UnderwritingRecommendation::band(30))->toBe('MEDIUM')->and(UnderwritingRecommendation::band(99))->toBe('HIGH');
    expect(RoleCatalogue::defaultPermissions('UNDERWRITER'))->toContain('underwriting.decide')->and(RoleCatalogue::defaultPermissions('SENIOR_UNDERWRITER'))->toContain('underwriting.assign');
});
