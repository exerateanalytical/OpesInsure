<?php

declare(strict_types=1);

/**
 * REQ-PRP-001 REQ-PRP-002 REQ-PRP-003 REQ-PRP-004 REQ-PRP-005 REQ-DUP-007 — Batch 6D proposal workflow:
 * proposal machine on the StateMachineEngine (blueprint info-request loop), proposal from an accepted quote offer
 * (Quote ≠ Proposal ≠ Policy), PROPOSAL-stage questions from question sets (legacy disclosure adapter kept),
 * catalogue document requirements, declarations with evidence, KYC + completeness gates, immutable submission
 * snapshots, cover terms, POLICY_ISSUABLE, mobile adapters backward compatible.
 */

use App\Application\Rules\Models\QuestionSet;
use App\Application\Underwriting\ProposalMachine;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use App\Models\CoverTermRule;
use App\Models\DocumentRequirementVersion;
use App\Models\InsuranceLine;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\ProposalSubmission;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\UnderwritingCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

/** A customer with an ACCEPTED offer (no proposal yet) on line AUTO. */
function b6dFixture(string $phone, array $offer = []): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['proposal']->update(['status' => 'WITHDRAWN']); // the helper's proposal is someone else's story
    $quote = Quote::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'line_code' => 'AUTO', 'status' => 'ACCEPTED', 'currency' => 'XAF', 'risk_facts' => ['usage' => 'PRIVATE']]);
    $f['offer'] = QuoteOffer::create(array_merge(['quote_id' => $quote->id, 'carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id, 'tariff_version_id' => $f['tariff']->id,
        'premium_minor' => 90000, 'tax_minor' => 6000, 'fee_minor' => 4000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'ACCEPTED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)], $offer));
    $f['quote'] = $quote;
    $f['uw'] = makeAuthTestUser($f['tenant'], ['underwriting.decide', 'proposals.issuability.read', 'documents.review'], 'UNDERWRITER');

    return $f;
}

function b6dLegacyDisclosures(): void
{
    $line = InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS']]),
        'schema_hash' => str_repeat('b', 64), 'effective_from' => '2026-01-01', 'created_by' => \App\Models\User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
}

/** An APPROVED PROPOSAL-stage question set for the product (rules engine, REQ-RUL-001). */
function b6dProposalQuestionSet(string $productId): QuestionSet
{
    $set = QuestionSet::create(['scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $productId, 'line_code' => 'AUTO', 'stage' => 'PROPOSAL', 'version' => 1,
        'status' => 'APPROVED', 'source' => 'MANUAL', 'schema_version' => 1, 'presentation' => ['steps' => [['key' => 'declarations', 'label' => 'Declarations']]],
        'schema_hash' => str_repeat('c', 64), 'effective_from' => '2026-01-01', 'approved_at' => now()]);
    $fields = [
        ['key' => 'prior_claims', 'label' => 'Claims in the last 3 years?', 'label_fr' => 'Sinistres ces 3 dernières années ?', 'type' => 'boolean', 'required' => true, 'step' => 'declarations', 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS'],
        ['key' => 'claims_count', 'label' => 'How many claims?', 'type' => 'number', 'required' => true, 'step' => 'declarations', 'visible_if' => ['prior_claims' => true]],
    ];
    foreach ($fields as $i => $f) {
        DB::table('product_questions')->insert(['id' => (string) Str::uuid(), 'question_set_id' => $set->id, 'code' => $f['key'], 'question_type' => 'BOOLEAN', 'input_type' => $f['type'],
            'label_en' => $f['label'], 'display_order' => $i + 1, 'required' => $f['required'], 'validation' => '{}', 'fact_key' => $f['key'], 'rendered_field' => json_encode($f), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $set;
}

function b6dCreate(array $f): string
{
    Passport::actingAs($f['user']);

    return test()->postJson('/api/v1/proposals', ['quote_offer_id' => $f['offer']->id, 'party_id' => $f['party']->id], tenantHeaderFor($f['tenant']))->assertCreated()->json('data.id');
}

it('REQ-PRP-001 REQ-PRP-002 REQ-DUP-007 runs the mobile disclosure → terms flow straight through on the proposal machine', function () {
    b6dLegacyDisclosures();
    $f = b6dFixture('+237673000001');
    $id = b6dCreate($f);
    $h = tenantHeaderFor($f['tenant']);

    $p = Proposal::findOrFail($id);
    expect($p->status)->toBe('DISCLOSURES_PENDING')->and($p->question_snapshot['source'])->toBe('LEGACY_DISCLOSURE_SCHEMA')
        ->and($p->terms_snapshot['total_minor'])->toBe(100000)->and($p->terms_snapshot['quote_id'])->toBe($f['quote']->id);

    // Mobile 1.3.0 contract unchanged: session → answers → submit (attest) → terms (submit).
    $s = $this->getJson("/api/v1/proposals/{$id}/disclosure", $h)->assertOk();
    expect($s->json('data.questions.0.id'))->toBe('prior_claims')->and($s->json('data.questions.0.type'))->toBe('boolean')->and($s->json('data.status'))->toBe('DISCLOSURES_PENDING');
    $this->putJson("/api/v1/proposals/{$id}/disclosure/answers", ['answers' => ['prior_claims' => 'no']], $h)->assertOk()->assertJsonPath('data.status', 'DOCUMENTS_PENDING')
        ->assertJsonPath('data.questions.0.answer', false);
    $this->postJson("/api/v1/proposals/{$id}/disclosure/submit", [], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/terms", ['accepted' => true], $h)->assertOk()->assertJsonPath('data.status', 'PAYMENT_PENDING')->assertJsonPath('data.accepted', true);

    $p->refresh();
    expect($p->submission_count)->toBe(1)->and($p->submitted_snapshot_hash)->toHaveLength(64)
        ->and(DB::table('proposal_declarations')->where('proposal_id', $id)->pluck('code')->sort()->values()->all())->toBe(['DISCLOSURE_ACCURACY', 'TERMS_ACCEPTANCE'])
        ->and(DB::table('proposal_declarations')->where('proposal_id', $id)->where('code', 'DISCLOSURE_ACCURACY')->value('channel'))->toBe('MOBILE')
        ->and(DB::table('proposal_status_history')->where('proposal_id', $id)->orderBy('occurred_at')->pluck('to_status')->all())->toContain('SUBMITTED', 'PAYMENT_PENDING')
        ->and(DB::table('workflow_transition_history')->where('machine', 'proposal')->where('subject_id', $id)->pluck('event')->all())->toBe(['open', 'complete_questions', 'submit', 'auto_approve'])
        ->and(UnderwritingCase::where('proposal_id', $id)->value('status'))->toBe('DECIDED');
    // The quote itself is never mutated by the proposal (LOCK-005).
    expect($f['quote']->fresh()->status)->toBe('ACCEPTED')->and(Policy::query()->where('proposal_id', $id)->exists())->toBeFalse();

    // Immutable submitted snapshot.
    $sub = ProposalSubmission::where('proposal_id', $id)->firstOrFail();
    expect($sub->snapshot['answers'])->toBe(['prior_claims' => false])->and(collect($sub->snapshot['declarations'])->pluck('code')->all())->toBe(['DISCLOSURE_ACCURACY', 'TERMS_ACCEPTANCE']);
    expect(fn () => DB::transaction(fn () => DB::table('proposal_submissions')->where('id', $sub->id)->update(['snapshot_hash' => str_repeat('0', 64)])))->toThrow(\Illuminate\Database\QueryException::class);
    // Submitted answers are locked (no silent mutation any more).
    $this->putJson("/api/v1/proposals/{$id}/disclosure/answers", ['answers' => ['prior_claims' => 'yes']], $h)->assertStatus(422);
});

it('REQ-PRP-001 REQ-PRP-002 answers PROPOSAL question sets, refers, and loops information_required → resubmitted', function () {
    $f = b6dFixture('+237673000002');
    $set = b6dProposalQuestionSet($f['product']->id);
    $id = b6dCreate($f);
    $h = tenantHeaderFor($f['tenant']);
    expect(Proposal::find($id)->question_set_id)->toBe($set->id);

    $s = $this->getJson("/api/v1/proposals/{$id}/disclosure", $h)->assertOk();
    expect($s->json('data.id'))->toBe($set->id)->and(collect($s->json('data.questions'))->pluck('id')->all())->toBe(['prior_claims', 'claims_count']);

    // claims_count is only required when prior_claims is true (visible_if).
    $this->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => true]], $h)->assertStatus(422)->assertJsonValidationErrors('answers.claims_count');
    $this->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => true, 'claims_count' => '2']], $h)->assertOk()->assertJsonPath('data.status', 'DOCUMENTS_PENDING');
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(422); // not attested
    $this->postJson("/api/v1/proposals/{$id}/disclosures/attest", [], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(202)->assertJsonPath('data.status', 'UNDER_REVIEW');
    $case = UnderwritingCase::where('proposal_id', $id)->firstOrFail();
    expect($case->status)->toBe('QUEUED')->and($case->referral_reasons)->toBe(['PRIOR_CLAIMS']);

    // Customers cannot ask for information; underwriters can.
    $this->postJson("/api/v1/proposals/{$id}/information-requests", ['items' => [['description' => 'Upload the claims history letter.']]], $h)->assertForbidden();
    Passport::actingAs($f['uw']);
    $this->postJson("/api/v1/proposals/{$id}/information-requests", ['items' => [['code' => 'CLAIMS_HISTORY', 'description' => 'Upload the claims history letter.']], 'message' => 'Please clarify'], $h)
        ->assertOk()->assertJsonPath('data.status', 'INFORMATION_REQUIRED');
    expect($case->fresh()->status)->toBe('AWAITING_INFORMATION');

    Passport::actingAs($f['user']);
    $check = $this->getJson("/api/v1/proposals/{$id}/checklist", $h)->assertOk();
    expect($check->json('data.blueprint_state'))->toBe('INFORMATION_REQUIRED')->and($check->json('data.available_transitions'))->toContain('resubmit', 'withdraw')
        ->and($check->json('data.information_request.items.0.code'))->toBe('CLAIMS_HISTORY');
    // Changing an answer resets the attestation: resubmit needs a fresh one.
    $this->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => true, 'claims_count' => '1']], $h)->assertOk()->assertJsonPath('data.status', 'INFORMATION_REQUIRED');
    $this->postJson("/api/v1/proposals/{$id}/resubmit", ['response' => 'Corrected: one claim.'], $h)->assertStatus(422);
    $this->postJson("/api/v1/proposals/{$id}/disclosures/attest", [], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/resubmit", ['response' => 'Corrected: one claim.'], $h)->assertStatus(202)->assertJsonPath('data.status', 'RESUBMITTED');

    expect($case->fresh()->status)->toBe('QUEUED');
    $subs = $this->getJson("/api/v1/proposals/{$id}/submissions", $h)->assertOk()->json('data');
    expect($subs)->toHaveCount(2)->and($subs[1]['kind'])->toBe('RESUBMIT')->and($subs[1]['snapshot']['answers']['claims_count'])->toBe('1')
        ->and($subs[1]['snapshot']['information_request']['response'])->toBe('Corrected: one claim.')->and($subs[0]['snapshot_hash'])->not->toBe($subs[1]['snapshot_hash']);

    // The underwriter decides through the machine (hook for UnderwritingService).
    $p = app(\App\Application\Underwriting\ProposalService::class)->applyUnderwritingDecision(Proposal::find($id), 'DECLINED', 'RISK_TOO_HIGH', null, $f['uw']);
    expect($p->status)->toBe('DECLINED');
    $this->postJson("/api/v1/proposals/{$id}/withdraw", [], $h)->assertStatus(422); // terminal
});

it('REQ-PRP-003 REQ-DUP-004 takes proposal document requirements from the document catalogue, not document_requirement_versions', function () {
    $this->seed(\Database\Seeders\DocumentCatalogueSeeder::class);
    b6dLegacyDisclosures();
    $f = b6dFixture('+237673000003');
    DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $f['product']->id, 'kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_TPL',
        'variant_code' => '', 'status' => 'ACTIVE', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    // A legacy mandatory requirement is no longer read by the proposal.
    DocumentRequirementVersion::create(['line_code' => 'AUTO', 'code' => 'LEGACY_ONLY_DOC', 'version' => 1, 'status' => 'APPROVED', 'mandatory' => true, 'rules' => [], 'name' => ['en' => 'Legacy only'], 'effective_from' => '2026-01-01', 'created_by' => $f['uw']->id]);
    $id = b6dCreate($f);
    $h = tenantHeaderFor($f['tenant']);

    $show = $this->getJson("/api/v1/proposals/{$id}", $h)->assertOk();
    $reqs = collect($show->json('data.required_documents'))->keyBy('code');
    expect($reqs->keys()->all())->not->toContain('LEGACY_ONLY_DOC')->not->toContain('INSURANCE_QUOTE')
        ->and($reqs['VEHICLE_REGISTRATION_CARD']['mandatory'])->toBeTrue()->and($reqs['VEHICLE_REGISTRATION_CARD']['satisfied_by'])->toBe('UPLOAD')->and($reqs['VEHICLE_REGISTRATION_CARD']['status'])->toBe('MISSING')
        ->and($reqs['INSURANCE_PROPOSAL']['satisfied_by'])->toBe('PROPOSAL_FORM')->and($reqs['DRIVER_SCHEDULE']['mandatory'])->toBeFalse()
        ->and($show->json('data.disclosure_schema.questions.0.code'))->toBe('prior_claims');

    $this->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => false]], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/disclosures/attest", [], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(422)->assertJsonValidationErrors('documents');

    $doc = makeMobileTestDocument($f['tenant'], $f['party'], ['scan_status' => 'PENDING']);
    $this->postJson("/api/v1/proposals/{$id}/documents", ['document_id' => $doc->id, 'requirement_code' => 'LEGACY_ONLY_DOC'], $h)->assertStatus(422);
    $this->postJson("/api/v1/proposals/{$id}/documents", ['document_id' => $doc->id, 'requirement_code' => 'VEHICLE_REGISTRATION_CARD'], $h)->assertCreated();
    $reqs = collect($this->getJson("/api/v1/proposals/{$id}/checklist", $h)->json('data.required_documents'))->keyBy('code');
    expect($reqs['VEHICLE_REGISTRATION_CARD']['status'])->toBe('UPLOADED')->and($reqs['INSURANCE_PROPOSAL']['status'])->toBe('ACCEPTED');

    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(202)->assertJsonPath('data.status', 'PAYMENT_PENDING');
    expect(DB::table('proposal_documents')->where('proposal_id', $id)->value('document_type_id'))->toBe('EVD-007');

    // POLICY_ISSUABLE needs the document ACCEPTED and a reconciled payment (REQ-PRP-004).
    Passport::actingAs($f['uw']);
    $iss = $this->getJson("/api/v1/proposals/{$id}/issuability", $h)->assertOk();
    expect($iss->json('data.issuable'))->toBeFalse()->and($iss->json('data.blockers'))->toContain('PAYMENT_NOT_RECEIVED', 'DOCUMENT_UPLOADED:VEHICLE_REGISTRATION_CARD');
    $this->postJson("/api/v1/proposals/{$id}/documents/{$doc->id}/review", ['decision' => 'VERIFIED', 'notes' => 'ok'], $h)->assertStatus(422); // not scanned clean
    $doc->update(['scan_status' => 'CLEAN']);
    // Owner decision 31: a VERIFIED row without a named reviewer (or approved automated control) is not accepted.
    DB::table('proposal_documents')->where('proposal_id', $id)->update(['status' => 'VERIFIED']);
    expect($this->getJson("/api/v1/proposals/{$id}/issuability", $h)->json('data.blockers'))->toContain('DOCUMENT_REVIEWING:VEHICLE_REGISTRATION_CARD');
    DB::table('proposal_documents')->where('proposal_id', $id)->update(['verified_by' => $f['uw']->id, 'verification_method' => 'MANUAL']);
    makeMobileTestPayment(Proposal::find($id), $f['tenant'], ['reconciled_at' => now()]);
    expect($this->getJson("/api/v1/proposals/{$id}/issuability", $h)->json('data'))->toMatchArray(['issuable' => true, 'blockers' => []]);
    Passport::actingAs($f['user']);
    $this->getJson("/api/v1/proposals/{$id}/issuability", $h)->assertForbidden();
});

it('REQ-PRP-002 enforces the KYC gate and the BIND completeness gate at submission', function () {
    b6dLegacyDisclosures();
    $f = b6dFixture('+237673000004');
    $id = b6dCreate($f);
    $h = tenantHeaderFor($f['tenant']);
    $this->putJson("/api/v1/proposals/{$id}/disclosures", ['answers' => ['prior_claims' => false]], $h)->assertOk();
    $this->postJson("/api/v1/proposals/{$id}/disclosures/attest", [], $h)->assertOk();

    DB::table('tenants')->where('id', $f['tenant']->id)->update(['settings' => json_encode(['kyc' => ['gate_mode' => 'ENFORCE']])]);
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(422)->assertJsonPath('code', 'KYC_REQUIRED');
    expect(Proposal::find($id)->status)->toBe('DOCUMENTS_PENDING')
        ->and(DB::table('audit_log')->where('action', 'kyc.gate.blocked')->where('subject_id', $id)->exists())->toBeTrue();
    DB::table('tenants')->where('id', $f['tenant']->id)->update(['settings' => json_encode([])]);

    $maker = makeAuthTestUser($f['tenant'], ['rules.view', 'rules.manage'], 'RULES_MAKER');
    $checker = makeAuthTestUser($f['tenant'], ['rules.view', 'rules.approve'], 'RULES_CHECKER');
    Passport::actingAs($maker);
    $rs = $this->postJson('/api/v1/rule-sets', ['code' => 'AUTO_BIND', 'domain' => 'COMPLETENESS', 'line_code' => 'AUTO', 'operation' => 'BIND', 'effective_from' => '2026-01-01', 'rules' => [
        ['code' => 'VIN_REQUIRED', 'condition' => ['op' => 'NOT_EXISTS', 'left' => ['fact' => 'vin']], 'outcome' => ['result' => 'BLOCK', 'reason_code' => 'VIN_REQUIRED']],
    ]], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/rule-sets/{$rs}/submit", [], $h)->assertOk();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/rule-sets/{$rs}/approve", ['note' => 'VIN needed at bind.'], $h)->assertOk();

    Passport::actingAs($f['user']);
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(422)->assertJsonValidationErrors('completeness');
    $f['quote']->update(['risk_facts' => ['usage' => 'PRIVATE', 'vin' => 'VF1ABC']]);
    $this->postJson("/api/v1/proposals/{$id}/submit", [], $h)->assertStatus(202);
});

it('REQ-PRP-001 refuses expired or unaccepted offers and a second live proposal', function () {
    b6dLegacyDisclosures();
    $f = b6dFixture('+237673000005', ['valid_until' => now()->subMinute()]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $f['offer']->id, 'party_id' => $f['party']->id], $h)->assertStatus(422)->assertJsonValidationErrors('quote_offer_id');
    $f['offer']->update(['valid_until' => now()->addDay(), 'status' => 'OFFERED']);
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $f['offer']->id, 'party_id' => $f['party']->id], $h)->assertStatus(422);
    $f['offer']->update(['status' => 'ACCEPTED']);
    $id = b6dCreate($f);
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $f['offer']->id, 'party_id' => $f['party']->id], $h)->assertStatus(422);
    $this->postJson("/api/v1/proposals/{$id}/withdraw", ['reason' => 'Changed my mind'], $h)->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
    expect(Proposal::find($id)->withdrawn_at)->not->toBeNull();
    b6dCreate($f); // a withdrawn proposal frees the offer
});

it('REQ-PRP-005 validates effective-date rules, durations and instalment plans', function () {
    b6dLegacyDisclosures();
    $f = b6dFixture('+237673000006');
    CoverTermRule::create(['insurance_product_id' => $f['product']->id, 'effective_date_rules' => ['IMMEDIATE', 'SPECIFIED_DATE', 'MIDNIGHT_RULE'], 'default_effective_rule' => 'IMMEDIATE',
        'durations' => [['unit' => 'MONTH', 'value' => 12], ['unit' => 'MONTH', 'value' => 6]], 'instalment_plans' => ['SINGLE', 'QUARTERLY'], 'max_advance_days' => 30,
        'instalment_fee_minor' => 500, 'effective_from' => '2026-01-01']);
    $id = b6dCreate($f);
    $h = tenantHeaderFor($f['tenant']);

    $t = $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['effective_rule' => 'SPECIFIED_DATE', 'start_date' => now('Africa/Douala')->addDays(3)->toDateString(),
        'duration' => ['unit' => 'MONTH', 'value' => 12], 'instalment_plan' => 'QUARTERLY'], $h)->assertOk()->json('data');
    expect($t['schedule'])->toHaveCount(4)->and(array_sum(array_column($t['schedule'], 'amount_minor')))->toBe(100000 + 3 * 500)
        ->and($t['schedule'][0]['due'])->toBe('AT_BIND')->and($t['non_payment_consequence'])->toBe('UNVERIFIED');
    $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['effective_rule' => 'SPECIFIED_DATE', 'start_date' => now()->subDays(2)->toDateString()], $h)->assertStatus(422)->assertJsonValidationErrors('start_date');
    $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['effective_rule' => 'SPECIFIED_DATE', 'start_date' => now()->addDays(60)->toDateString()], $h)->assertStatus(422);
    $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['instalment_plan' => 'MONTHLY'], $h)->assertStatus(422)->assertJsonValidationErrors('instalment_plan');
    $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['duration' => ['unit' => 'MONTH', 'value' => 3]], $h)->assertStatus(422)->assertJsonValidationErrors('duration');
    $this->putJson("/api/v1/proposals/{$id}/cover-terms", ['effective_rule' => 'PAYMENT_DATE'], $h)->assertStatus(422);
    $one = $this->putJson("/api/v1/proposals/{$id}/cover-terms", [], $h)->assertOk()->json('data');
    expect($one['instalment_plan'])->toBe('SINGLE')->and($one['schedule'])->toEqual([['sequence' => 1, 'due' => 'AT_BIND', 'amount_minor' => 100000, 'fee_minor' => 0]]);
    expect(app(\App\Application\Policies\CoverTermsService::class)->resolveStart(['effective_rule' => 'MIDNIGHT_RULE', 'timezone' => 'Africa/Douala'], new DateTimeImmutable('2026-10-10 15:00:00', new DateTimeZone('Africa/Douala')))->format('Y-m-d H:i'))->toBe('2026-10-11 00:00');
});

it('REQ-PRP-001 REQ-WFL-001 registers a valid proposal machine with blueprint state names', function () {
    $m = app(StateMachineRegistry::class)->get('proposal');
    expect($m->initialState())->toBe('DRAFT')->and($m->hasState('INFORMATION_REQUIRED'))->toBeTrue()->and($m->hasState('RESUBMITTED'))->toBeTrue()
        ->and($m->transitionFor('INFORMATION_REQUIRED', 'resubmit')?->to)->toBe('RESUBMITTED')
        ->and($m->transitionFor('PAYMENT_PENDING', 'withdraw'))->toBeNull()
        ->and(ProposalMachine::blueprintState('UNDER_REVIEW'))->toBe('REVIEWING')->and(ProposalMachine::blueprintState('PAYMENT_PENDING'))->toBe('APPROVED');
    // proposals.status CHECK accepts the new states.
    $f = b6dFixture('+237673000007');
    $f['proposal']->update(['status' => 'INFORMATION_REQUIRED']);
    expect($f['proposal']->fresh()->status)->toBe('INFORMATION_REQUIRED');
});
