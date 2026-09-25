<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Events\OutboxWriter;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Application\Kyc\Risk\CustomerRiskRatingService;
use App\Application\Kyc\Screening\ScreeningMode;
use App\Application\Kyc\Screening\ScreeningAdapter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use App\Models\KycSubmission;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-KYC-001 / 002 / 003 — canonical KYC service (REQ-DUP-007: MobileKycService is a thin adapter over it).
 *
 * kyc_submissions is the KYC record; the staff review runs as a KYC_REVIEW case (KycCaseType) on the
 * case engine, whose transitions this service drives and mirrors onto the submission status.
 * Maker-checker: a reviewer recommends (kyc.review), a different user decides (kyc.decide); the decision
 * is an append-only case decision. Remediation creates a new submission; the original is retained.
 */
final class KycService
{
    public const EDITABLE = ['DRAFT', 'MORE_INFO_REQUIRED'];

    public const IN_REVIEW = ['SUBMITTED', 'REVIEWING', 'MORE_INFO_REQUIRED', 'PENDING_APPROVAL'];

    public function __construct(
        private readonly CaseService $cases,
        private readonly KycRequirementService $requirements,
        private readonly KycLevelResolver $levels,
        private readonly ScreeningAdapter $screening,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly CustomerRiskRatingService $risk,
    ) {}

    public static function kindFor(Party $party): string
    {
        return in_array(strtoupper((string) $party->type), ['ORGANIZATION', 'ORGANISATION', 'COMPANY', 'CORPORATE'], true) ? 'CORPORATE' : 'INDIVIDUAL';
    }

    /** Latest editable submission of the party in the tenant, or a new DRAFT. */
    public function draftFor(Party $party, string $tenantId): KycSubmission
    {
        return KycSubmission::where('tenant_id', $tenantId)->where('party_id', $party->id)->whereIn('status', self::EDITABLE)->latest('created_at')->first()
            ?? KycSubmission::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'status' => 'DRAFT', 'subject_kind' => self::kindFor($party)]);
    }

    public function latest(string $tenantId, string $partyId): ?KycSubmission
    {
        return KycSubmission::where('tenant_id', $tenantId)->where('party_id', $partyId)->latest('created_at')->first();
    }

    public function attachDocument(KycSubmission $s, Document $document, string $purpose, ?User $actor): KycSubmission
    {
        if (! in_array($s->status, self::EDITABLE, true)) {
            throw $this->problem('KYC_NOT_EDITABLE', 409, 'Documents can only be added while the submission is a draft or more information is required.');
        }
        if ($document->tenant_id !== $s->tenant_id || $document->party_id !== $s->party_id) {
            throw $this->problem('KYC_DOCUMENT_NOT_OWNED', 422, 'The document does not belong to the KYC subject.');
        }
        if ($document->scan_status === 'INFECTED') {
            throw $this->problem('KYC_DOCUMENT_INFECTED', 422, __('wave12.kyc_document_infected'), 'document_id');
        }
        // Pre-check rather than catching the unique violation as flow control (Postgres aborts the transaction).
        if ($s->documents()->where('documents.id', $document->id)->exists()) {
            throw $this->problem('KYC_DOCUMENT_ALREADY_ATTACHED', 409, __('wave12.kyc_document_already_attached'), 'document_id');
        }
        try {
            DB::transaction(fn () => $s->documents()->attach($document->id, ['purpose' => strtoupper($purpose)]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }
            throw $this->problem('KYC_DOCUMENT_ALREADY_ATTACHED', 409, __('wave12.kyc_document_already_attached'), 'document_id');
        }
        $this->audit->record('kyc_submission.document_attached', 'kyc_submission', $s->id, ['document_id' => $document->id, 'purpose' => strtoupper($purpose), 'actor_id' => $actor?->id]);

        return $s->fresh();
    }

    /** DRAFT → SUBMITTED (opens the KYC_REVIEW case, starts screening); MORE_INFO_REQUIRED → REVIEWING. */
    public function submit(KycSubmission $s, ?string $notes, ?User $actor): KycSubmission
    {
        if (! in_array($s->status, self::EDITABLE, true)) {
            throw $this->problem('KYC_NOT_EDITABLE', 409, 'Only a draft or a submission awaiting information can be submitted.');
        }
        if ($s->documents()->count() === 0) {
            throw $this->problem('KYC_NO_DOCUMENTS', 422, __('wave12.kyc_no_documents'), 'submission');
        }
        if ($s->documents()->where('scan_status', '!=', 'CLEAN')->exists()) {
            throw $this->problem('KYC_DOCUMENTS_NOT_READY', 422, __('wave12.kyc_documents_not_ready'), 'submission');
        }
        if ($s->status === 'MORE_INFO_REQUIRED') {
            return DB::transaction(function () use ($s, $notes, $actor) {
                $this->cases->transition($this->caseOf($s), 'info_received', $actor, null, ['kyc_submission_id' => $s->id]);
                $s->update(['status' => 'REVIEWING', 'notes' => $notes ?? $s->notes, 'version' => $s->version + 1]);
                $this->audit->record('kyc_submission.resubmitted', 'kyc_submission', $s->id, ['party_id' => $s->party_id]);

                return $s->fresh();
            });
        }
        $other = KycSubmission::where('tenant_id', $s->tenant_id)->where('party_id', $s->party_id)->where('id', '<>', $s->id)->whereIn('status', self::IN_REVIEW)->exists();
        if ($other) {
            throw $this->problem('KYC_REVIEW_IN_PROGRESS', 409, 'Another KYC submission of this party is already under review.');
        }
        $party = Party::findOrFail($s->party_id);

        return DB::transaction(function () use ($s, $notes, $actor, $party) {
            $kind = self::kindFor($party);
            $s->update(['subject_kind' => $kind]);
            [$level, $factors] = $this->levels->compute($s->fresh(), []);
            $case = $this->cases->open($s->tenant_id, KycCaseType::CODE, [
                'title' => 'KYC review — '.$party->display_name, 'subject_type' => 'kyc_submission', 'subject_id' => $s->id,
                'source_type' => 'kyc_submission', 'source_id' => $s->id, 'idempotency_key' => 'kyc-submission:'.$s->id,
            ], $actor);
            $s->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'notes' => $notes, 'case_id' => $case->id,
                'kyc_level' => $level, 'level_source' => 'COMPUTED', 'risk_factors' => $factors, 'version' => $s->version + 1]);
            $this->screen($s, $party);
            $this->reassess($s->fresh(), [], [], $actor?->id, 'Submitted for review.');
            $this->audit->record('kyc_submission.submitted', 'kyc_submission', $s->id, ['party_id' => $s->party_id, 'case_id' => $case->id, 'kyc_level' => $level]);
            $this->outbox->record('kyc_submission.submitted', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id, 'case_id' => $case->id, 'kyc_level' => $level]);

            return $s->fresh();
        });
    }

    public function startReview(KycSubmission $s, User $actor): KycSubmission
    {
        return $this->move($s, 'start_review', 'REVIEWING', $actor, null, ['SUBMITTED']);
    }

    public function requestInformation(KycSubmission $s, string $reason, User $actor): KycSubmission
    {
        $s = $this->move($s, 'request_info', 'MORE_INFO_REQUIRED', $actor, $reason, ['SUBMITTED', 'REVIEWING', 'PENDING_APPROVAL'], ['recommended_outcome' => null, 'recommended_by' => null, 'recommended_at' => null, 'recommendation_rationale' => null]);
        $this->outbox->record('kyc_submission.information_requested', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id, 'reason' => $reason]);

        return $s;
    }

    /** Reviewer raises the level (never below the computed one). */
    public function setLevel(KycSubmission $s, string $level, string $reason, User $actor): KycSubmission
    {
        if (! in_array($s->status, ['SUBMITTED', 'REVIEWING'], true)) {
            throw $this->problem('KYC_LEVEL_LOCKED', 409, 'The level can only change before a recommendation is made.');
        }
        [$computed] = $this->levels->compute($s, $s->risk_factors ?? []);
        if (KycLevelResolver::rank($level) < KycLevelResolver::rank($computed)) {
            throw $this->problem('KYC_LEVEL_TOO_LOW', 422, "The level cannot be lower than the risk-based level ({$computed}).", 'kyc_level');
        }
        $old = $s->kyc_level;
        $s->update(['kyc_level' => $level, 'level_source' => $level === $computed ? 'COMPUTED' : 'OVERRIDE', 'version' => $s->version + 1]);
        $this->audit->recordChange('kyc_submission.level_changed', 'kyc_submission', $s->id, ['kyc_level' => $old], ['kyc_level' => $level], $reason);

        return $s->fresh();
    }

    /**
     * Records a reviewer's result for a PENDING (MANUAL) screening check. A possible / confirmed match adds the
     * risk factor and raises the level to ENHANCED.
     *
     * @param  array{status: string, list_reference?: ?string, notes?: ?string}  $d
     */
    public function recordScreening(KycSubmission $s, ScreeningCheck $check, array $d, User $actor): KycSubmission
    {
        if ($check->subject_type !== 'kyc_submission' || $check->subject_id !== $s->id) {
            throw $this->problem('SCREENING_NOT_FOUND', 404, 'Screening check not found on this submission.');
        }
        $rescreen = (int) ($check->screening_round ?? 1) > 1;
        if (! in_array($s->status, ['SUBMITTED', 'REVIEWING'], true) && ! ($rescreen && $s->status === 'APPROVED')) {
            throw $this->problem('KYC_SCREENING_LOCKED', 409, 'Screening results are recorded while the submission is under review (or, for a rescreening, while it is approved).');
        }
        if ($check->status !== 'PENDING' && $rescreen) {
            throw $this->problem('KYC_SCREENING_RECORDED', 409, 'This rescreening result is already recorded.');
        }

        return DB::transaction(function () use ($s, $check, $d, $actor, $rescreen) {
            $check->update(['status' => $d['status'], 'list_reference' => $d['list_reference'] ?? null, 'notes' => $d['notes'] ?? null, 'checked_by' => $actor->id, 'checked_at' => now()]);
            $this->audit->record('kyc_submission.screening_recorded', 'kyc_submission', $s->id, ['check_id' => $check->id, 'check_type' => $check->check_type, 'status' => $d['status'],
                'screening_round' => (int) ($check->screening_round ?? 1), 'screening_mode' => ScreeningMode::normalize($check->provider), 'automated' => false, 'list_reference' => $d['list_reference'] ?? null]);
            if ($rescreen) {
                // An approved KYC is never silently changed: a match is re-rated and raised for review / remediation.
                $this->reassess($s, [], [], $actor->id, 'Rescreening result recorded.', false);
                if ($d['status'] !== 'CLEAR') {
                    $this->outbox->record('kyc_submission.rescreen_match', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id,
                        'party_id' => $s->party_id, 'check_id' => $check->id, 'check_type' => $check->check_type, 'status' => $d['status']]);
                }

                return $s->fresh();
            }
            $s->update(['screening_status' => $this->screeningStatus($s)]);
            $factors = $s->risk_factors ?? [];
            if ($d['status'] !== 'CLEAR') {
                $factors[] = $check->check_type.'_'.$d['status'];
            }
            [$level, $factors] = $this->levels->compute($s->fresh(), array_values(array_unique($factors)));
            if (KycLevelResolver::rank($level) > KycLevelResolver::rank((string) $s->kyc_level)) {
                $s->update(['kyc_level' => $level, 'level_source' => 'COMPUTED']);
            }
            $s->update(['risk_factors' => $factors, 'version' => $s->version + 1]);
            if ($d['status'] !== 'CLEAR') {
                $this->reassess($s->fresh(), [], [], $actor->id, 'Screening match recorded.');
            }

            return $s->fresh();
        });
    }

    /**
     * Owner decision 27: reviewer (re)assesses customer risk with the facts the platform does not hold itself
     * (country, products, channel, reviewer-declared factors such as PEP). Raises the level when warranted.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function assessRisk(KycSubmission $s, array $inputs, string $reason, User $actor): KycSubmission
    {
        if (! in_array($s->status, ['SUBMITTED', 'REVIEWING'], true)) {
            throw $this->problem('KYC_RISK_LOCKED', 409, 'Customer risk is assessed while the submission is under review.');
        }

        return DB::transaction(function () use ($s, $inputs, $reason, $actor) {
            $this->reassess($s, $inputs, [], $actor->id, $reason);

            return $s->fresh();
        });
    }

    /**
     * Records the customer's declared source of funds / source of wealth (with evidence document ids) on a new
     * assessment version. Required before approval when the risk configuration says so (EDD by default).
     *
     * @param  array<string, mixed>|null  $funds
     * @param  array<string, mixed>|null  $wealth
     */
    public function declareSources(KycSubmission $s, ?array $funds, ?array $wealth, string $reason, User $actor): KycSubmission
    {
        if (! in_array($s->status, [...self::EDITABLE, 'SUBMITTED', 'REVIEWING'], true)) {
            throw $this->problem('KYC_NOT_EDITABLE', 409, 'Source of funds / wealth is recorded before the KYC is decided.');
        }
        foreach ([$funds, $wealth] as $decl) {
            foreach ((array) ($decl['evidence_document_ids'] ?? []) as $docId) {
                if (! Document::whereKey($docId)->where('tenant_id', $s->tenant_id)->where('party_id', $s->party_id)->exists()) {
                    throw $this->problem('KYC_DOCUMENT_NOT_OWNED', 422, 'An evidence document does not belong to the KYC subject.', 'evidence_document_ids');
                }
            }
        }
        $decl = array_filter(['source_of_funds' => $funds, 'source_of_wealth' => $wealth], fn ($v) => $v !== null);

        return DB::transaction(function () use ($s, $decl, $reason, $actor) {
            $this->reassess($s, [], array_map(fn ($d) => $d + ['declared_at' => now()->toIso8601String(), 'recorded_by' => $actor->id], $decl), $actor->id, $reason, $s->status !== 'DRAFT');
            $this->audit->record('kyc_submission.sources_declared', 'kyc_submission', $s->id, ['fields' => array_keys($decl), 'reason' => $reason]);

            return $s->fresh();
        });
    }

    /**
     * Periodic / ad-hoc rescreening of an APPROVED KYC (owner decision 27). Opens a new screening round through the
     * configured adapter (MANUAL_AUDITED: every check PENDING for a reviewer). Nothing is claimed as automated.
     */
    public function rescreen(KycSubmission $s, string $trigger, string $reason, ?User $actor): KycSubmission
    {
        if ($s->status !== 'APPROVED') {
            throw $this->problem('KYC_NOT_APPROVED', 409, 'Only an approved KYC is rescreened.');
        }
        $checks = ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $s->id);
        if ((clone $checks)->where('status', 'PENDING')->exists()) {
            throw $this->problem('KYC_RESCREEN_IN_PROGRESS', 409, 'A screening round of this KYC is still pending.');
        }
        $round = (int) (clone $checks)->max('screening_round') + 1;
        $party = Party::findOrFail($s->party_id);

        return DB::transaction(function () use ($s, $party, $round, $trigger, $reason, $actor) {
            $this->screen($s, $party, $round, $trigger, false);
            $this->audit->record('kyc_submission.rescreen_started', 'kyc_submission', $s->id, ['screening_round' => $round, 'trigger' => $trigger,
                'screening_mode' => ScreeningMode::normalize($this->screening->code()), 'automated' => false, 'actor_id' => $actor?->id, 'reason' => $reason]);
            $this->outbox->record('kyc_submission.rescreen_started', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id,
                'party_id' => $s->party_id, 'screening_round' => $round, 'trigger' => $trigger]);

            return $s->fresh();
        });
    }

    /** APPROVED KYC whose current assessment's next_rescreen_at is due (scheduled daily: kyc:rescreen-due). */
    public function rescreenDue(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        foreach (KycSubmission::where('status', 'APPROVED')->whereNull('superseded_by_submission_id')->pluck('id') as $id) {
            $s = KycSubmission::find($id);
            $a = $this->risk->latest($s);
            if (! $a?->next_rescreen_at || $a->next_rescreen_at->gt($now)
                || ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $id)->where('status', 'PENDING')->exists()) {
                continue;
            }
            $this->rescreen($s, 'PERIODIC_RESCREEN', 'Periodic rescreening due ('.$a->rating.').', null);
            $this->reassess($s->fresh(), [], [], null, 'Periodic rescreening opened.', false);
            $n++;
        }

        return $n;
    }

    /**
     * New risk assessment version; feeds HIGH_RISK / EDD_REQUIRED into the level resolver (never lowers the level).
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $declarations
     */
    private function reassess(KycSubmission $s, array $inputs, array $declarations, ?string $actorId, ?string $reason, bool $touchLevel = true): void
    {
        $a = $this->risk->assess($s, $inputs, $declarations, $actorId, $reason);
        if (! $touchLevel || ! in_array($s->status, ['SUBMITTED', 'REVIEWING'], true)) {
            return;
        }
        $factors = array_values(array_unique([...($s->risk_factors ?? []), ...CustomerRiskRatingService::levelFactors($a), ...($a->triggers['facts'] ?? [])]));
        [$level, $factors] = $this->levels->compute($s, $factors);
        $upd = ['risk_factors' => $factors];
        if (KycLevelResolver::rank($level) > KycLevelResolver::rank((string) $s->kyc_level)) {
            $upd += ['kyc_level' => $level, 'level_source' => 'COMPUTED'];
        }
        $s->update($upd);
    }

    /** Maker step: REVIEWING → PENDING_APPROVAL with a recommended outcome. */
    public function recommend(KycSubmission $s, string $outcome, string $rationale, User $actor): KycSubmission
    {
        if ($s->status !== 'REVIEWING') {
            throw $this->problem('KYC_NOT_IN_REVIEW', 409, 'A recommendation needs a submission in review.');
        }
        if ($outcome === 'APPROVE') {
            $this->assertApprovable($s);
        }

        return $this->move($s, 'recommend', 'PENDING_APPROVAL', $actor, $rationale, ['REVIEWING'], [
            'recommended_outcome' => $outcome, 'recommendation_rationale' => $rationale, 'recommended_by' => $actor->id, 'recommended_at' => now(),
        ]);
    }

    /** Checker step: confirm (records the case decision, then approve / reject) or return to review. */
    public function decide(KycSubmission $s, bool $confirm, string $reason, User $actor): KycSubmission
    {
        if ($s->status !== 'PENDING_APPROVAL') {
            throw $this->problem('KYC_NOT_PENDING_APPROVAL', 409, 'Only a submission pending approval can be decided.');
        }
        if ($s->recommended_by === $actor->id) {
            throw $this->problem('MAKER_CHECKER', 403, 'Maker-checker: the reviewer who recommended cannot decide the same KYC.');
        }
        if (! $confirm) {
            return $this->move($s, 'return_to_review', 'REVIEWING', $actor, $reason, ['PENDING_APPROVAL'], ['recommended_outcome' => null, 'recommended_by' => null, 'recommended_at' => null]);
        }
        $approve = $s->recommended_outcome === 'APPROVE';
        if ($approve) {
            $this->assertApprovable($s);
        }

        return DB::transaction(function () use ($s, $approve, $reason, $actor) {
            $case = $this->caseOf($s);
            $decision = $this->cases->decide($case, ['decision_type' => 'KYC_VERIFICATION', 'outcome' => $approve ? 'APPROVED' : 'REJECTED', 'rationale' => $reason,
                'conditions' => ['kyc_level' => $s->kyc_level, 'recommended_by' => $s->recommended_by]], $actor);
            $extra = ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'decision_reason' => $reason];
            if ($approve) {
                [$expires, $basis] = $this->expiry($s);
                $extra += ['approved_at' => now(), 'expires_at' => $expires, 'expiry_basis' => $basis];
            }
            $s = $this->move($s, $approve ? 'approve' : 'reject', $approve ? 'APPROVED' : 'REJECTED', $actor, $reason, ['PENDING_APPROVAL'], $extra, ['outcome' => $approve ? 'APPROVED' : 'REJECTED', 'decision_id' => $decision->id]);
            $payload = ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id,
                'kyc_level' => $s->kyc_level, 'expires_at' => $s->expires_at?->toIso8601String(), 'decision_id' => $decision->id];
            if ($approve) {
                $this->outbox->record('kyc_submission.approved', 'kyc_submission', $s->id, $payload);
            } else {
                $this->outbox->record('kyc_submission.rejected', 'kyc_submission', $s->id, $payload);
            }
            if ($approve && $s->supersedes_submission_id) {
                $this->audit->record('kyc_submission.remediation_completed', 'kyc_submission', $s->supersedes_submission_id, ['replacement_id' => $s->id]);
            }

            return $s;
        });
    }

    /** REQ-KYC-003: start a refresh / remediation. A new DRAFT supersedes the original, which is retained unchanged. */
    public function remediate(KycSubmission $s, string $reason, ?User $actor): KycSubmission
    {
        if (! in_array($s->status, ['APPROVED', 'EXPIRED', 'REJECTED'], true)) {
            throw $this->problem('KYC_NOT_REMEDIABLE', 409, 'Only an approved, expired or rejected KYC can be remediated.');
        }
        if ($s->superseded_by_submission_id) {
            throw $this->problem('KYC_ALREADY_REMEDIATING', 409, 'A remediation submission already exists for this KYC.');
        }

        return DB::transaction(function () use ($s, $reason, $actor) {
            $new = KycSubmission::create(['tenant_id' => $s->tenant_id, 'party_id' => $s->party_id, 'status' => 'DRAFT', 'subject_kind' => $s->subject_kind,
                'supersedes_submission_id' => $s->id, 'remediation_reason' => $reason]);
            $s->update(['superseded_by_submission_id' => $new->id]);
            $this->audit->record('kyc_submission.remediation_requested', 'kyc_submission', $s->id, ['replacement_id' => $new->id, 'actor_id' => $actor?->id], $reason);
            $this->outbox->record('kyc_submission.remediation_requested', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'replacement_id' => $new->id,
                'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id, 'reason' => $reason]);

            return $new->fresh();
        });
    }

    /** REQ-KYC-003: APPROVED submissions past expires_at become EXPIRED (scheduled daily: kyc:expire). */
    public function expireDue(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        foreach (KycSubmission::where('status', 'APPROVED')->whereNotNull('expires_at')->where('expires_at', '<=', $now)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $now, &$n) {
                $s = KycSubmission::whereKey($id)->lockForUpdate()->first();
                if (! $s || $s->status !== 'APPROVED') {
                    return;
                }
                $s->update(['status' => 'EXPIRED', 'expired_at' => $now, 'version' => $s->version + 1]);
                $this->audit->record('kyc_submission.expired', 'kyc_submission', $s->id, ['expires_at' => $s->expires_at?->toIso8601String(), 'expiry_basis' => $s->expiry_basis]);
                $this->outbox->record('kyc_submission.expired', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id]);
                $n++;
            });
        }

        return $n;
    }

    /** Read model for staff and mobile. @return array<string, mixed> */
    public function present(KycSubmission $s, bool $staff = false): array
    {
        $eval = $this->requirements->evaluate($s);
        $out = [
            'id' => $s->id, 'status' => $s->status, 'subject_kind' => $s->subject_kind, 'kyc_level' => $s->kyc_level,
            'notes' => $s->notes, 'submitted_at' => $s->submitted_at?->toIso8601String(), 'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'approved_at' => $s->approved_at?->toIso8601String(), 'expires_at' => $s->expires_at?->toIso8601String(), 'expired_at' => $s->expired_at?->toIso8601String(),
            'supersedes_submission_id' => $s->supersedes_submission_id, 'superseded_by_submission_id' => $s->superseded_by_submission_id,
            'remediation_reason' => $s->remediation_reason,
            'requirements' => array_map(fn ($r) => ['requirement_code' => $r['requirement_code'], 'applies_to' => $r['applies_to'], 'mandatory' => $r['mandatory'],
                'accepted_canonical_codes' => $r['accepted_canonical_codes'], 'satisfied' => $r['satisfied']], $eval['requirements']),
            'missing_requirements' => $eval['missing'],
            'documents' => $s->documents()->get()->map(fn ($d) => [
                'id' => $d->id, 'purpose' => $d->pivot->purpose, 'category' => $d->category, 'scan_status' => $d->scan_status,
                'verification_status' => $d->verification_status, 'ocr_data' => $d->ocr_data,
            ])->all(),
        ];
        if ($staff) {
            $out += [
                'party_id' => $s->party_id, 'case_id' => $s->case_id, 'level_source' => $s->level_source, 'risk_factors' => $s->risk_factors ?? [],
                'screening_status' => $s->screening_status, 'recommended_outcome' => $s->recommended_outcome, 'recommended_by' => $s->recommended_by,
                'recommendation_rationale' => $s->recommendation_rationale, 'reviewed_by' => $s->reviewed_by, 'decision_reason' => $s->decision_reason,
                'expiry_basis' => $s->expiry_basis, 'version' => $s->version,
                'requirements_detail' => $eval['requirements'],
                'screenings' => ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $s->id)->orderBy('created_at')->get()
                    ->map(fn ($c) => $c->only(['id', 'check_type', 'provider', 'status', 'list_reference', 'notes', 'checked_by', 'checked_at', 'screening_round', 'trigger'])
                        + ['screening_mode' => ScreeningMode::normalize($c->provider), 'automated' => false])->all(),
                'corporate' => $s->subject_kind === 'CORPORATE' ? $this->corporateStatus($s) : null,
                'risk_assessment' => $this->risk->present($this->risk->latest($s)),
                'screening' => ScreeningMode::describe(),
            ];
        }

        return $out;
    }

    /** @return array{ubos: list<array<string, mixed>>, unresolved: list<string>, threshold: ?float, blocking: list<string>, available: bool} */
    public function corporateStatus(KycSubmission $s): array
    {
        if (! class_exists(PartyRelationshipService::class)) {
            return ['ubos' => [], 'unresolved' => [], 'threshold' => null, 'blocking' => ['UBO_REGISTER_UNAVAILABLE'], 'available' => false];
        }
        $graph = app(PartyRelationshipService::class)->ultimateBeneficialOwners(Party::findOrFail($s->party_id));
        $blocking = [];
        $ubos = [];
        foreach ($graph['owners'] as $o) {
            $kyc = KycSubmission::where('tenant_id', $s->tenant_id)->where('party_id', $o['party_id'])->where('status', 'APPROVED')->latest('approved_at')->first();
            $ubos[] = ['party_id' => $o['party_id'], 'display_name' => $o['display_name'], 'effective_percentage' => $o['effective_percentage'], 'grounds' => $o['grounds'] ?? [], 'roles' => $o['roles'] ?? [], 'kyc_status' => $kyc ? 'APPROVED' : 'MISSING'];
            if (! $kyc && config('kyc.corporate.require_ubo_kyc', true)) {
                $blocking[] = 'UBO_KYC_MISSING:'.$o['party_id'];
            }
        }
        if ($graph['owners'] === []) {
            $blocking[] = 'UBO_NOT_IDENTIFIED';
        }
        foreach ($graph['unresolved'] as $u) {
            $blocking[] = 'UBO_UNRESOLVED_OWNER:'.$u;
        }

        return ['ubos' => $ubos, 'unresolved' => $graph['unresolved'], 'threshold' => $graph['threshold'], 'threshold_rule' => $graph['threshold_rule'] ?? 'GREATER_THAN', 'blocking' => $blocking, 'available' => true];
    }

    public function caseOf(KycSubmission $s): WorkCase
    {
        return $s->case_id ? WorkCase::withoutGlobalScopes()->findOrFail($s->case_id)
            : throw $this->problem('KYC_CASE_MISSING', 409, 'This submission has no KYC review case (it was never submitted).');
    }

    private function assertApprovable(KycSubmission $s): void
    {
        $blocking = $this->requirements->evaluate($s)['missing'];
        $pending = ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $s->id)->where('status', 'PENDING')->exists();
        if ($pending) {
            $blocking[] = 'SCREENING_PENDING';
        }
        if ($s->screening_status === 'CONFIRMED_MATCH') {
            $blocking[] = 'SCREENING_CONFIRMED_MATCH';
        }
        if ($s->subject_kind === 'CORPORATE') {
            $blocking = array_merge($blocking, $this->corporateStatus($s)['blocking']);
        }
        $blocking = array_merge($blocking, $this->risk->blocking($s));
        if ($blocking !== []) {
            throw new ApiProblemException('KYC_NOT_APPROVABLE', 422, 'The KYC cannot be approved yet.', [], ['blocking' => $blocking]);
        }
    }

    private function screen(KycSubmission $s, Party $party, int $round = 1, string $trigger = 'ONBOARDING', bool $updateStatus = true): void
    {
        foreach (config('kyc.screening.checks', ['SANCTIONS', 'PEP']) as $type) {
            $r = $this->screening->screen($party, $type);
            ScreeningCheck::create(['tenant_id' => $s->tenant_id, 'party_id' => $party->id, 'subject_type' => 'kyc_submission', 'subject_id' => $s->id,
                'check_type' => $type, 'provider' => ScreeningMode::normalize($this->screening->code()), 'status' => $r['status'] ?? 'PENDING',
                'list_reference' => $r['list_reference'] ?? null, 'result' => $r['result'] ?? [], 'checked_at' => $r ? now() : null,
                'screening_round' => $round, 'trigger' => $trigger]);
        }
        if ($updateStatus) {
            $s->update(['screening_status' => $this->screeningStatus($s)]);
        }
    }

    private function screeningStatus(KycSubmission $s): string
    {
        $statuses = ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $s->id)->pluck('status')->all();
        foreach (['CONFIRMED_MATCH', 'POSSIBLE_MATCH', 'PENDING'] as $worst) {
            if (in_array($worst, $statuses, true)) {
                return $worst === 'PENDING' ? 'NOT_SCREENED' : $worst;
            }
        }

        return $statuses ? 'CLEAR' : 'NOT_SCREENED';
    }

    /** @return array{0: ?Carbon, 1: string} */
    private function expiry(KycSubmission $s): array
    {
        $candidates = [];
        $eval = $this->requirements->evaluate($s);
        $used = array_merge(...array_map(fn ($r) => $r['satisfied_by'], $eval['requirements'] ?: [['satisfied_by' => []]]));
        $docDate = $used ? Document::whereIn('id', $used)->whereNotNull('valid_until')->min('valid_until') : null;
        if ($docDate) {
            $candidates['DOCUMENT_VALID_UNTIL'] = Carbon::parse($docDate);
        }
        $months = $this->requirements->refreshMonths($s);
        if ($months) {
            $candidates['REFRESH_POLICY'] = now()->addMonthsNoOverflow($months);
        }
        $riskMonths = $this->risk->latest($s)?->refresh_months;   // decision 27: periodic refresh by risk rating
        if ($riskMonths) {
            $candidates['RISK_REFRESH_POLICY'] = now()->addMonthsNoOverflow($riskMonths);
        }
        if (! $candidates) {
            return [null, 'NONE'];
        }
        asort($candidates);
        $basis = array_key_first($candidates);

        return [$candidates[$basis], $basis];
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $payload
     */
    private function move(KycSubmission $s, string $event, string $to, ?User $actor, ?string $reason, array $from, array $extra = [], array $payload = []): KycSubmission
    {
        return DB::transaction(function () use ($s, $event, $to, $actor, $reason, $from, $extra, $payload) {
            $s = KycSubmission::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if (! in_array($s->status, $from, true)) {
                throw $this->problem('KYC_INVALID_STATE', 409, "Cannot {$event} a submission in status {$s->status}.");
            }
            $this->cases->transition($this->caseOf($s), $event, $actor, $reason, $payload + ['kyc_submission_id' => $s->id]);
            $old = $s->status;
            $s->update(['status' => $to, 'version' => $s->version + 1] + $extra);
            $this->audit->record('kyc_submission.'.$event, 'kyc_submission', $s->id, ['from' => $old, 'to' => $to], $reason);

            return $s->fresh();
        });
    }

    private function problem(string $code, int $status, string $message, ?string $field = null): ApiProblemException
    {
        return new ApiProblemException($code, $status, $message, $field ? [$field => [$message]] : []);
    }
}
