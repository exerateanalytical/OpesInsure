<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Application\Claims\ClaimEvidenceService;
use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-005 WF-051 (accept) / WF-052 (reject) evidence review with a structured reason.
 * Builds on ClaimEvidenceService::verify() — which keeps owning the hash re-check, the link status
 * (VERIFIED/REJECTED) and the custody event — and adds: only SUBMITTED evidence is reviewable, the
 * reviewer is not the submitter, a reason code is mandatory for a rejection, and the review is
 * recorded on the link (review_reason_code / notes) and emitted as claim.evidence.reviewed.
 */
final class ClaimEvidenceReviewService
{
    public const ACCEPT = 'ACCEPT';

    public const REJECT = 'REJECT';

    public function __construct(private ClaimEvidenceService $evidence, private OutboxWriter $outbox, private ClaimEvidenceChecklist $checklist) {}

    /** @return array<string, mixed> the claim's refreshed checklist */
    public function review(Claim $claim, Document $document, string $decision, ?string $reasonCode, ?string $notes, User $actor): array
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, [self::ACCEPT, self::REJECT], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision must be ACCEPT or REJECT.']);
        }
        $reasonCode = $reasonCode !== null && trim($reasonCode) !== '' ? strtoupper(trim($reasonCode)) : null;
        if ($decision === self::REJECT && $reasonCode === null) {
            throw ValidationException::withMessages(['reason_code' => 'A reason code is required to reject evidence.']);
        }
        $reasonCode ??= 'EVIDENCE_ACCEPTED';

        DB::transaction(function () use ($claim, $document, $decision, $reasonCode, $notes, $actor) {
            $link = DB::table('claim_documents')->where(['claim_id' => $claim->id, 'document_id' => $document->id])->lockForUpdate()->first();
            if (! $link || $claim->tenant_id !== app(\App\Domain\Tenancy\TenantContext::class)->id()) {
                abort(404);
            }
            if ($link->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['status' => 'Only evidence awaiting review can be reviewed.']);
            }
            if ($link->submitted_by !== null && $link->submitted_by === $actor->id) {
                throw ValidationException::withMessages(['document_id' => 'Evidence cannot be reviewed by the person who submitted it.']);
            }
            $accepted = $decision === self::ACCEPT;
            $this->evidence->verify($claim, $document, $accepted, substr('EVIDENCE_REVIEW:'.$reasonCode, 0, 96), $actor);
            DB::table('claim_documents')->where(['claim_id' => $claim->id, 'document_id' => $document->id])
                ->update(['review_reason_code' => $reasonCode, 'rejection_reason' => $accepted ? null : ($notes ?? $reasonCode)]);
            $this->outbox->record('claim.evidence.reviewed', 'claim', $claim->id, [
                'document_id' => $document->id, 'decision' => $decision, 'reason_code' => $reasonCode, 'reviewed_by' => $actor->id,
            ]);
        });

        return $this->checklist->build($claim->refresh());
    }
}
