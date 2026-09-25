<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Kyc\KycGate;
use App\Application\Policies\CoverTermsService;
use App\Application\Rules\RuleEngine;
use App\Application\Shared\CanonicalJson;
use App\Application\Underwriting\Proposal\ProposalDeclarations;
use App\Application\Underwriting\Proposal\ProposalDocumentRequirements;
use App\Application\Underwriting\Proposal\ProposalQuestions;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Models\Document;
use App\Models\Proposal;
use App\Models\ProposalDisclosureResponse;
use App\Models\ProposalDocument;
use App\Models\ProposalSubmission;
use App\Models\QuoteOffer;
use App\Models\Tenant;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\UnderwritingReferralTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * THE proposal application service (REQ-PRP-001…005, REQ-DUP-007): every channel — web API, mobile adapters
 * (MobileProposalService, MobileDisclosureController), back office — goes through here.
 *
 *  create        accepted, unexpired quote offer → proposal (Quote ≠ Proposal ≠ Policy, LOCK-005: terms are copied,
 *                the quote is never mutated); PROPOSAL-stage questions frozen from the rules engine's question sets.
 *  answer        answers validated against the frozen questions; referral flags from the question definitions.
 *  attest        answers-hash check + DISCLOSURE_ACCURACY declaration (append-only evidence, REQ-PRP-002).
 *  attachDocument / verifyDocument  requirements from DocumentCatalogueService::requirementsFor (REQ-PRP-003).
 *  submit        declarations + documents + KycGate (BIND) + RuleEngine::assertComplete(BIND) → immutable snapshot →
 *                underwriting case; straight-through (no referral flag) → PAYMENT_PENDING, else UNDER_REVIEW.
 *  requestInformation / resubmit    blueprint information_required → resubmitted loop.
 *  withdraw, respondToCounterOffer, applyUnderwritingDecision (for UnderwritingService).
 * Every status change goes through ProposalMachine on the StateMachineEngine (workflow history + domain event) and is
 * mirrored in proposal_status_history (the table existing screens read).
 */
final class ProposalService
{
    public function __construct(
        private CanonicalJson $json,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
        private StateMachineEngine $engine,
        private ProposalQuestions $questions,
        private ProposalDocumentRequirements $requirements,
        private ProposalDeclarations $declarations,
        private KycGate $kyc,
        private RuleEngine $rules,
        private CoverTermsService $coverTerms,
    ) {
        // Machine guards (defence in depth: submit()/resubmit() also check these with precise messages first).
        $this->engine->registerGuard('proposal.attested', fn ($t, TransitionContext $c) => $c->subject instanceof Proposal && $c->subject->attested_at !== null);
        $this->engine->registerGuard('proposal.documents_complete', fn ($t, TransitionContext $c) => $c->subject instanceof Proposal && $this->requirements->missing($c->subject) === []);
    }

    // ------------------------------------------------------------------ creation

    public function create(Tenant $t, QuoteOffer $o, array $d, User $actor): Proposal
    {
        return DB::transaction(function () use ($t, $o, $d, $actor): Proposal {
            $o->load(['quote', 'product']);
            if ($o->quote->tenant_id !== $t->id || $o->status !== 'ACCEPTED') {
                throw ValidationException::withMessages(['quote_offer_id' => __('wave3.accepted_offer_required')]);
            }
            if ($o->quote->party_id !== $d['party_id']) {
                throw ValidationException::withMessages(['party_id' => __('wave3.party_mismatch')]);
            }
            if ($o->valid_until !== null && $o->valid_until->isPast()) {
                throw ValidationException::withMessages(['quote_offer_id' => __('wave3.offer_expired')]);
            }
            if (Proposal::where('quote_offer_id', $o->id)->where('status', '!=', 'WITHDRAWN')->exists()) {
                throw ValidationException::withMessages(['quote_offer_id' => __('wave3.proposal_exists')]);
            }
            $questionnaire = $this->questions->resolve($o->product, (string) $o->quote->line_code);
            if ($questionnaire === null) {
                throw ValidationException::withMessages(['disclosures' => __('wave3.disclosure_schema_required')]);
            }

            $p = Proposal::create([
                'tenant_id' => $t->id, 'quote_offer_id' => $o->id, 'party_id' => $d['party_id'],
                'proposal_number' => 'PRP-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'status' => 'DRAFT', 'disclosures' => [], 'version' => 1, 'created_by' => $actor->id,
                'terms_snapshot' => [
                    'offer_id' => $o->id, 'quote_id' => $o->quote_id, 'product_id' => $o->product_id, 'carrier_id' => $o->carrier_id,
                    'premium_minor' => $o->premium_minor, 'tax_minor' => $o->tax_minor, 'fee_minor' => $o->fee_minor, 'total_minor' => $o->total_minor,
                    'currency' => $o->currency, 'tariff_version_id' => $o->tariff_version_id, 'coverage_snapshot' => $o->coverage_snapshot,
                ],
                'question_set_id' => $questionnaire['question_set_id'],
                'disclosure_schema_version_id' => $questionnaire['disclosure_schema_version_id'],
                'question_snapshot' => $questionnaire,
                'question_snapshot_hash' => $this->json->hash($questionnaire),
            ]);
            $this->move($p, 'open', $actor, 'CREATED', [], null);
            $this->audit->record('proposal.created', 'proposal', $p->id, ['proposal_number' => $p->proposal_number, 'questionnaire' => $questionnaire['source']]);
            $this->outbox->record('proposal.created', 'proposal', $p->id, ['proposal_id' => $p->id]);

            return $p->refresh();
        });
    }

    // ------------------------------------------------------------------ questions / declarations

    /** @return list<array<string,mixed>> PROPOSAL-stage questions (disclosure shape) this proposal answers */
    public function questions(Proposal $p): array
    {
        return $this->questions->of($p);
    }

    public function answer(Proposal $p, array $answers, User $actor): Proposal
    {
        return DB::transaction(function () use ($p, $answers, $actor): Proposal {
            $p = $this->lock($p);
            if (! in_array($p->status, ProposalMachine::ANSWERABLE, true)) {
                throw ValidationException::withMessages(['status' => __('wave3.disclosures_locked')]);
            }
            $questions = $this->questions->of($p);
            $this->questions->assertAnswered($questions, $answers);
            $flags = $this->questions->referralFlags($questions, $answers);
            $hash = $this->json->hash($answers);

            $key = $p->question_set_id !== null || $p->disclosure_schema_version_id === null
                ? ['proposal_id' => $p->id, 'disclosure_schema_version_id' => null]
                : ['proposal_id' => $p->id, 'disclosure_schema_version_id' => $p->disclosure_schema_version_id];
            $existing = ProposalDisclosureResponse::where($key)->first();
            $changed = $existing?->answers_hash !== $hash;
            ProposalDisclosureResponse::updateOrCreate($key, ['question_set_id' => $p->question_set_id, 'answers' => $answers, 'referral_flags' => $flags, 'answers_hash' => $hash]
                + ($changed ? ['attested_at' => null, 'attested_by' => null] : []));

            $attrs = ['disclosures' => $answers, 'version' => $p->version + 1] + ($changed ? ['attested_at' => null] : []);
            if (in_array($p->status, ['DRAFT', 'DISCLOSURES_PENDING'], true)) {
                $this->move($p, 'complete_questions', $actor, 'DISCLOSURES_COMPLETED', ['referral_flags' => $flags], $attrs);
            } else {
                $p->update($attrs);
                $this->audit->record('proposal.disclosures.amended', 'proposal', $p->id, ['status' => $p->status, 'answers_hash' => $hash, 'referral_flags' => $flags]);
            }

            return $p->refresh();
        });
    }

    /**
     * Attestation of the current answers (REQ-PRP-002): checks the stored answers hash, stamps the response and the
     * proposal, and appends the DISCLOSURE_ACCURACY declaration (+ any extra declaration codes) with evidence.
     *
     * @param  list<string>  $declarations  extra codes from config('proposals.declarations')
     */
    public function attest(Proposal $p, User $actor, array $declarations = [], string $channel = 'API', array $context = []): Proposal
    {
        return DB::transaction(function () use ($p, $actor, $declarations, $channel, $context): Proposal {
            $p = $this->lock($p);
            $r = $p->disclosureResponse()->first();
            if (! $r) {
                throw ValidationException::withMessages(['answers' => __('wave3.disclosures_required')]);
            }
            if ($this->json->hash($r->answers) !== $r->answers_hash) {
                throw ValidationException::withMessages(['answers' => __('wave3.answers_hash_mismatch')]);
            }
            $r->update(['attested_at' => now(), 'attested_by' => $actor->id]);
            $p->update(['attested_at' => now()]);
            foreach (array_unique(['DISCLOSURE_ACCURACY', ...$declarations]) as $code) {
                $this->declarations->accept($p, (string) $code, $actor, $channel, $context);
            }
            $this->audit->record('proposal.disclosures.attested', 'proposal', $p->id, ['schema_id' => $r->disclosure_schema_version_id, 'question_set_id' => $r->question_set_id, 'answers_hash' => $r->answers_hash]);

            return $p->refresh();
        });
    }

    /** Accept one declaration / consent (e.g. TERMS_ACCEPTANCE from the app's terms screen). */
    public function declare(Proposal $p, string $code, User $actor, string $channel = 'API', array $context = []): Proposal
    {
        if (in_array($p->status, ProposalMachine::TERMINAL, true)) {
            throw ValidationException::withMessages(['status' => __('wave3.proposal_closed')]);
        }
        $this->declarations->accept($p, $code, $actor, $channel, $context);

        return $p->refresh();
    }

    // ------------------------------------------------------------------ documents

    /** @return list<array<string,mixed>> catalogue requirements with MISSING/UPLOADED/REVIEWING/ACCEPTED/REJECTED/EXPIRED */
    public function requiredDocuments(Proposal $p): array
    {
        return $this->requirements->for($p);
    }

    public function attachDocument(Proposal $p, Document $d, string $requirementCode): ProposalDocument
    {
        if ($d->tenant_id !== $p->tenant_id || $d->party_id !== $p->party_id) {
            throw ValidationException::withMessages(['document_id' => __('wave3.document_ownership')]);
        }
        if (in_array($p->status, [...ProposalMachine::TERMINAL, 'PAYMENT_PENDING', 'APPROVED'], true)) {
            throw ValidationException::withMessages(['status' => __('wave3.proposal_closed')]);
        }
        $req = $this->requirements->uploadable($p, $requirementCode);
        if (! $req) {
            throw ValidationException::withMessages(['requirement_code' => __('wave3.requirement_invalid')]);
        }

        return ProposalDocument::updateOrCreate(['proposal_id' => $p->id, 'document_id' => $d->id], [
            'requirement_code' => $req['code'], 'document_type_id' => $req['document_type_id'], 'requirement_source' => 'CATALOGUE',
            'document_requirement_version_id' => null, 'status' => 'SUBMITTED',
        ]);
    }

    public function verifyDocument(Proposal $p, Document $d, string $decision, string $notes, User $actor): ProposalDocument
    {
        $link = ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->firstOrFail();
        if ($d->scan_status !== 'CLEAN' && $decision === 'VERIFIED') {
            throw ValidationException::withMessages(['status' => __('wave3.clean_document_required')]);
        }
        ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->update(['status' => $decision, 'verified_by' => $actor->id, 'verified_at' => now(), 'review_notes' => $notes,
            'verification_method' => $decision === 'VERIFIED' ? 'MANUAL' : null, 'automated_control_code' => null]);
        $this->audit->record('proposal.document.reviewed', 'document', $d->id, ['proposal_id' => $p->id, 'decision' => $decision]);

        return ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->firstOrFail();
    }

    // ------------------------------------------------------------------ cover terms (REQ-PRP-005)

    public function selectCoverTerms(Proposal $p, array $in, User $actor): Proposal
    {
        return DB::transaction(function () use ($p, $in, $actor): Proposal {
            $p = $this->lock($p);
            if (! in_array($p->status, ProposalMachine::ANSWERABLE, true)) {
                throw ValidationException::withMessages(['status' => __('wave3.proposal_locked')]);
            }
            $offer = $p->offer()->with(['quote', 'product'])->firstOrFail();
            $terms = $this->coverTerms->select($offer->product, (string) $offer->quote->line_code, (int) ($p->terms_snapshot['total_minor'] ?? 0), $in);
            $p->update(['cover_terms' => $terms, 'version' => $p->version + 1]);
            $this->audit->record('proposal.cover_terms.selected', 'proposal', $p->id, ['effective_rule' => $terms['effective_rule'], 'plan' => $terms['instalment_plan'], 'by' => $actor->id]);

            return $p->refresh();
        });
    }

    // ------------------------------------------------------------------ submission / underwriting loop

    public function submit(Proposal $p, User $actor): Proposal
    {
        $p = $p->refresh();
        if ($p->status !== 'DOCUMENTS_PENDING' || ! $p->attested_at) {
            throw ValidationException::withMessages(['status' => __('wave3.proposal_not_submittable')]);
        }
        $this->assertReady($p, $actor);

        return DB::transaction(function () use ($p, $actor): Proposal {
            $p = $this->lock($p);
            if ($p->status !== 'DOCUMENTS_PENDING') {
                throw ValidationException::withMessages(['status' => __('wave3.proposal_not_submittable')]);
            }
            $flags = $p->disclosureResponse()->value('referral_flags');
            $flags = is_string($flags) ? (json_decode($flags, true) ?? []) : ($flags ?? []);
            $submission = $this->snapshot($p, 'SUBMIT', $actor);
            $this->move($p, 'submit', $actor, 'SUBMITTED', ['submission_id' => $submission->id], [
                'submitted_at' => now(), 'submission_count' => $submission->sequence, 'submitted_snapshot_hash' => $submission->snapshot_hash, 'version' => $p->version + 1,
            ]);

            $case = UnderwritingCase::create(['tenant_id' => $p->tenant_id, 'proposal_id' => $p->id, 'carrier_id' => $p->offer->carrier_id, 'status' => 'QUEUED',
                'priority' => $flags ? 'HIGH' : 'NORMAL', 'referral_reasons' => $flags, 'decision_due_at' => now()->addWeekdays(2)]);
            foreach ($flags as $f) {
                UnderwritingReferralTask::create(['underwriting_case_id' => $case->id, 'reason_code' => $f, 'severity' => 'HIGH', 'due_at' => $case->decision_due_at]);
            }
            // Straight-through processing: no referral flag → no human underwriter needed; the decision is recorded.
            if ($flags === []) {
                UnderwritingDecision::create(['underwriting_case_id' => $case->id, 'decision' => 'APPROVED', 'reason_code' => 'STRAIGHT_THROUGH',
                    'notes' => 'Automatically approved: no disclosure raised a referral flag.', 'conditions' => [], 'decided_by' => $actor->id, 'decided_at' => now()]);
                $case->update(['status' => 'DECIDED']);
                $this->move($p->refresh(), 'auto_approve', $actor, 'STRAIGHT_THROUGH', ['underwriting_case_id' => $case->id], ['decided_at' => now(), 'version' => $p->version + 1]);
            } else {
                $this->move($p->refresh(), 'refer', $actor, 'REFERRED', ['underwriting_case_id' => $case->id, 'referral_flags' => $flags], ['version' => $p->version + 1]);
            }
            $this->audit->record('proposal.submitted', 'proposal', $p->id, ['underwriting_case_id' => $case->id, 'snapshot_hash' => $submission->snapshot_hash]);
            $this->outbox->record('proposal.submitted', 'proposal', $p->id, ['proposal_id' => $p->id, 'underwriting_case_id' => $case->id, 'submission_id' => $submission->id]);

            return $p->refresh();
        });
    }

    /**
     * Underwriter asks the proposer for more information (blueprint information_required).
     *
     * @param  list<array{code?:string, description:string}>  $items
     */
    public function requestInformation(Proposal $p, array $items, ?string $message, User $actor): Proposal
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'List at least one item of information to provide.']);
        }

        return DB::transaction(function () use ($p, $items, $message, $actor): Proposal {
            $p = $this->lock($p);
            $request = ['id' => (string) Str::uuid(), 'items' => array_values($items), 'message' => $message, 'requested_by' => $actor->id,
                'requested_at' => now()->toIso8601String(), 'responded_at' => null];
            $this->move($p, 'request_information', $actor, 'INFORMATION_REQUESTED', ['request_id' => $request['id'], 'items' => count($items)],
                ['information_request' => $request, 'version' => $p->version + 1]);
            UnderwritingCase::where('proposal_id', $p->id)->whereIn('status', ['QUEUED', 'IN_REVIEW'])->update(['status' => 'AWAITING_INFORMATION']);
            $this->audit->record('proposal.information.requested', 'proposal', $p->id, ['items' => $items], $message);

            return $p->refresh();
        });
    }

    /** Proposer answered the information request: new immutable snapshot, back to the underwriter. */
    public function resubmit(Proposal $p, User $actor, ?string $response = null): Proposal
    {
        $p = $p->refresh();
        if ($p->status !== 'INFORMATION_REQUIRED' || ! $p->attested_at) {
            throw ValidationException::withMessages(['status' => __('wave3.proposal_not_submittable')]);
        }
        $this->assertReady($p, $actor);

        return DB::transaction(function () use ($p, $actor, $response): Proposal {
            $p = $this->lock($p);
            $request = $p->information_request ?? [];
            $request['responded_at'] = now()->toIso8601String();
            $request['response'] = $response;
            $p->information_request = $request; // part of the snapshot
            $submission = $this->snapshot($p, 'RESUBMIT', $actor);
            $this->move($p, 'resubmit', $actor, 'RESUBMITTED', ['submission_id' => $submission->id], [
                'information_request' => $request, 'submission_count' => $submission->sequence, 'submitted_snapshot_hash' => $submission->snapshot_hash, 'version' => $p->version + 1,
            ]);
            $case = UnderwritingCase::where('proposal_id', $p->id)->latest('created_at')->first();
            if ($case && $case->status === 'AWAITING_INFORMATION') {
                $case->update(['status' => $case->assigned_to ? 'IN_REVIEW' : 'QUEUED']);
            }
            $this->audit->record('proposal.resubmitted', 'proposal', $p->id, ['snapshot_hash' => $submission->snapshot_hash], $response);

            return $p->refresh();
        });
    }

    public function withdraw(Proposal $p, User $actor, ?string $reason = null): Proposal
    {
        return DB::transaction(function () use ($p, $actor, $reason): Proposal {
            $p = $this->lock($p);
            $this->move($p, 'withdraw', $actor, 'WITHDRAWN_BY_PROPOSER', ['reason' => $reason], ['withdrawn_at' => now(), 'version' => $p->version + 1]);
            UnderwritingCase::where('proposal_id', $p->id)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION'])->update(['status' => 'CANCELLED']);
            $this->audit->record('proposal.withdrawn', 'proposal', $p->id, [], $reason);

            return $p->refresh();
        });
    }

    /**
     * The proposer's answer to an underwriter counter-offer (UnderwritingService::decide COUNTEROFFERED). The counter
     * terms are the decision's `conditions` (revised_* or plain *_minor). Accept → revised terms, PAYMENT_PENDING;
     * decline → WITHDRAWN (the proposer walked away; DECLINED is the insurer's decision).
     */
    public function respondToCounterOffer(Proposal $proposal, bool $accept, User $user, string $channel = 'API'): Proposal
    {
        return DB::transaction(function () use ($proposal, $accept, $user, $channel): Proposal {
            $proposal = $this->lock($proposal);
            if ($proposal->status !== 'COUNTEROFFERED') {
                throw ValidationException::withMessages(['status' => 'This proposal has no open counter-offer.']);
            }
            if ($accept) {
                $counter = $this->counterTerms($proposal);
                $terms = $proposal->terms_snapshot ?? [];
                foreach (['premium_minor', 'tax_minor', 'fee_minor', 'total_minor'] as $k) {
                    if (isset($counter[$k])) {
                        $terms[$k] = (int) $counter[$k];
                    }
                }
                $terms['counter_offer_decision_id'] = $counter['decision_id'] ?? null;
                $this->move($proposal, 'accept_counteroffer', $user, 'COUNTEROFFER_ACCEPTED', ['channel' => $channel], ['terms_snapshot' => $terms, 'version' => $proposal->version + 1]);
                $this->audit->record('proposal.counteroffer.accepted', 'proposal', $proposal->id, [], 'COUNTEROFFER_ACCEPTED');
                $this->outbox->record('proposal.counteroffer.accepted', 'proposal', $proposal->id, ['proposal_id' => $proposal->id, 'status' => 'PAYMENT_PENDING']);
            } else {
                $this->move($proposal, 'decline_counteroffer', $user, 'COUNTEROFFER_DECLINED', ['channel' => $channel], ['withdrawn_at' => now(), 'version' => $proposal->version + 1]);
                $this->audit->record('proposal.counteroffer.declined', 'proposal', $proposal->id, [], 'COUNTEROFFER_DECLINED');
                $this->outbox->record('proposal.counteroffer.declined', 'proposal', $proposal->id, ['proposal_id' => $proposal->id, 'status' => 'WITHDRAWN']);
            }

            return $proposal->refresh();
        });
    }

    /**
     * Status side of an underwriting decision, through the machine. For UnderwritingService (batch 7B) to call instead
     * of writing proposals.status itself. APPROVED → PAYMENT_PENDING, COUNTEROFFERED, DECLINED.
     */
    public function applyUnderwritingDecision(Proposal $p, string $decision, string $reasonCode, ?string $decisionId, User $actor): Proposal
    {
        $event = match ($decision) {
            'APPROVED' => 'approve', 'COUNTEROFFERED' => 'counteroffer', 'DECLINED' => 'decline',
            default => throw ValidationException::withMessages(['decision' => "Unknown decision {$decision}."]),
        };
        $p = $this->lock($p);
        $this->move($p, $event, $actor, $reasonCode, ['decision_id' => $decisionId], ['decided_at' => now(), 'version' => $p->version + 1]);

        return $p->refresh();
    }

    /** @return array<string,int|string|null>|null the open counter-offer terms */
    public function counterTerms(Proposal $proposal): ?array
    {
        $decision = UnderwritingDecision::whereHas('underwritingCase', fn ($q) => $q->where('proposal_id', $proposal->id))
            ->where('decision', 'COUNTEROFFERED')->latest('decided_at')->first();
        if (! $decision) {
            return null;
        }
        $c = $decision->conditions ?? [];
        $pick = fn (string $k) => isset($c["revised_{$k}"]) ? (int) $c["revised_{$k}"] : (isset($c[$k]) ? (int) $c[$k] : null);

        return [
            'decision_id' => $decision->id, 'premium_minor' => $pick('premium_minor'), 'tax_minor' => $pick('tax_minor'), 'fee_minor' => $pick('fee_minor'),
            'total_minor' => $pick('total_minor'), 'notes' => $decision->notes, 'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ read models

    /** Everything the proposer / reviewer needs to finish the proposal, with the blockers for the next step. */
    public function checklist(Proposal $p, ?User $viewer = null): array
    {
        $documents = $this->requirements->for($p);
        $missingDeclarations = $this->missingDeclarations($p);
        $kyc = $this->kyc->status($p->tenant_id, $p->party_id) + ['gate_mode' => $this->kyc->mode($p->tenant_id)];
        $blocking = [];
        if (! $p->attested_at) {
            $blocking[] = 'ATTESTATION_REQUIRED';
        }
        foreach ($missingDeclarations as $c) {
            $blocking[] = 'DECLARATION_REQUIRED:'.$c;
        }
        foreach ($documents as $d) {
            if ($d['mandatory'] && ! in_array($d['status'], ['UPLOADED', 'REVIEWING', 'ACCEPTED'], true)) {
                $blocking[] = 'DOCUMENT_'.$d['status'].':'.$d['code'];
            }
        }
        if ($kyc['gate_mode'] === 'ENFORCE' && ! $kyc['verified']) {
            $blocking[] = 'KYC_REQUIRED:'.$kyc['reason'];
        }
        $catalogue = [];
        foreach ($this->declarations->catalogue() as $code => $def) {
            $catalogue[] = ['code' => $code, 'version' => $def['version'], 'legal_status' => $def['legal_status'] ?? 'UNVERIFIED_LEGAL_WORDING', 'statement' => $def['statement'],
                'required_for_submit' => (bool) ($def['required_for_submit'] ?? false), 'accepted' => $this->declarations->accepted($p, $code) !== null];
        }

        return [
            'proposal_id' => $p->id, 'status' => $p->status, 'blueprint_state' => ProposalMachine::blueprintState($p->status), 'version' => $p->version,
            'questionnaire' => $this->questions->metaOf($p), 'questions' => $this->questions->of($p), 'answers' => $p->disclosures ?? [],
            'attested_at' => $p->attested_at?->toIso8601String(), 'declarations' => $catalogue, 'required_documents' => $documents, 'kyc' => $kyc,
            'cover_terms' => $p->cover_terms, 'cover_term_rule' => $this->coverTerms->ruleFor($p->offer?->product, $p->offer?->quote?->line_code),
            'information_request' => $p->information_request, 'submission_count' => $p->submission_count, 'submitted_snapshot_hash' => $p->submitted_snapshot_hash,
            'available_transitions' => $this->availableEvents($p, $viewer), 'blocking' => $blocking,
        ];
    }

    /** @return list<string> events the machine allows from the current state */
    public function availableEvents(Proposal $p, ?User $actor = null): array
    {
        return array_map(fn ($t) => $t->event, $this->engine->available(ProposalMachine::definition(), $this->context($p, $actor, null, [])));
    }

    // ------------------------------------------------------------------ internals

    /** Declarations + documents + KYC gate (BIND) + completeness gate (BIND); outside the write transaction so gate audits persist. */
    private function assertReady(Proposal $p, User $actor): void
    {
        if ($missing = $this->missingDeclarations($p)) {
            throw ValidationException::withMessages(['declarations' => __('wave3.declaration_missing', ['code' => $missing[0]])]);
        }
        if ($missing = $this->requirements->missing($p)) {
            throw ValidationException::withMessages(['documents' => __('wave3.document_missing', ['code' => $missing[0]])]);
        }
        $this->kyc->assertMayProceed($p->tenant_id, $p->party_id, 'BIND', 'proposal', $p->id);
        $offer = $p->offer()->with(['quote', 'product'])->firstOrFail();
        $this->rules->assertComplete('BIND', (string) $offer->quote->line_code, $offer->product, $this->facts($p, $offer), ['type' => 'proposal', 'id' => $p->id], $p->tenant_id);
    }

    /** Required declarations not yet accepted; a legacy (pre-6D) hash-checked response attestation stands for DISCLOSURE_ACCURACY. */
    private function missingDeclarations(Proposal $p): array
    {
        $missing = $this->declarations->missing($p);
        $r = $p->disclosureResponse()->first();
        if ($r?->attested_at && $p->attested_at && $this->json->hash($r->answers) === $r->answers_hash) {
            $missing = array_values(array_diff($missing, ['DISCLOSURE_ACCURACY']));
        }

        return $missing;
    }

    /** Fact map for the BIND completeness gate: quote risk facts + proposal answers + proposal context. */
    private function facts(Proposal $p, QuoteOffer $offer): array
    {
        $facts = [];
        foreach ((array) ($offer->quote->risk_facts ?? []) as $k => $v) {
            $facts['risk.'.$k] = $v;
            $facts[$k] = $v;
        }
        foreach ((array) ($p->disclosures ?? []) as $k => $v) {
            $facts['proposal.answers.'.$k] = $v;
        }

        return $facts + ['proposal.attested' => $p->attested_at !== null, 'proposal.total_minor' => (int) ($p->terms_snapshot['total_minor'] ?? 0),
            'proposal.instalment_plan' => $p->cover_terms['instalment_plan'] ?? 'SINGLE', 'proposal.party_id' => $p->party_id];
    }

    /** Append-only, hashed snapshot of everything submitted (REQ-PRP-001 immutable submitted snapshot). */
    private function snapshot(Proposal $p, string $kind, User $actor): ProposalSubmission
    {
        $r = $p->disclosureResponse()->first();
        $sequence = (int) ProposalSubmission::where('proposal_id', $p->id)->max('sequence') + 1;
        $offer = $p->offer()->with('quote')->firstOrFail();
        $snapshot = [
            'proposal_id' => $p->id, 'proposal_number' => $p->proposal_number, 'sequence' => $sequence, 'kind' => $kind, 'party_id' => $p->party_id,
            'quote_offer_id' => $p->quote_offer_id, 'quote_id' => $offer->quote_id, 'product_id' => $offer->product_id, 'carrier_id' => $offer->carrier_id,
            'line_code' => $offer->quote->line_code, 'risk_facts' => $offer->quote->risk_facts ?? [], 'terms' => $p->terms_snapshot ?? [], 'cover_terms' => $p->cover_terms,
            'questionnaire' => $this->questions->metaOf($p), 'answers' => $r?->answers ?? [], 'answers_hash' => $r?->answers_hash, 'referral_flags' => $r?->referral_flags ?? [],
            'declarations' => $this->declarations->summary($p),
            'documents' => array_map(fn ($d) => array_intersect_key($d, array_flip(['code', 'document_type_id', 'level', 'mandatory', 'satisfied_by', 'status', 'document_id'])), $this->requirements->for($p)),
            'kyc' => $this->kyc->status($p->tenant_id, $p->party_id), 'information_request' => $p->information_request,
            'submitted_by' => $actor->id, 'submitted_at' => now()->toIso8601String(),
        ];

        return ProposalSubmission::create(['proposal_id' => $p->id, 'sequence' => $sequence, 'kind' => $kind, 'snapshot' => $snapshot,
            'snapshot_hash' => $this->json->hash($snapshot), 'submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    /**
     * One machine transition: engine (guards, workflow history, domain event) → persist status (+ $attrs) → the
     * proposal_status_history row existing screens read.
     */
    private function move(Proposal $p, string $event, ?User $actor, string $reasonCode, array $meta, ?array $attrs): void
    {
        $from = $p->status;
        try {
            $result = $this->engine->apply(ProposalMachine::definition(), $event, $this->context($p, $actor, $reasonCode, $meta));
        } catch (TransitionDenied $e) {
            throw ValidationException::withMessages(['status' => $e->stage === TransitionDenied::INVALID ? __('wave3.proposal_transition_invalid', ['from' => $from, 'event' => $event]) : $e->getMessage()]);
        }
        $p->update(['status' => $result->to] + ($attrs ?? []));
        DB::table('proposal_status_history')->insert(['id' => (string) Str::uuid(), 'proposal_id' => $p->id, 'from_status' => $from === 'DRAFT' && $event === 'open' ? null : $from,
            'to_status' => $result->to, 'reason_code' => $reasonCode, 'actor_id' => $actor?->id, 'metadata' => json_encode($meta + ['event' => $event]), 'occurred_at' => now()]);
    }

    private function context(Proposal $p, ?User $actor, ?string $reason, array $meta): TransitionContext
    {
        return new TransitionContext('proposal', $p->id, $p->status, $actor, null, $meta, $reason, $p);
    }

    private function lock(Proposal $p): Proposal
    {
        return Proposal::whereKey($p->id)->lockForUpdate()->firstOrFail();
    }
}
