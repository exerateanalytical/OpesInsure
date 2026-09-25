<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\DocumentStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revoke / replace / cancel an issued document — maker-checker (requester
 * can never decide), reason mandatory, never a delete: the document keeps its
 * bytes, number and verification code, and verification then answers
 * REVOKED / REPLACED / CANCELLED. REPLACE links the successor both ways.
 * expireDue() persists EXPIRED for proof-of-cover documents past validity.
 * REQ-RBAC-005: decisions go through the central ApprovalService (action document.status_change).
 */
final class DocumentStatusService
{
    public function __construct(private AuditWriter $audit, private ApprovalService $approvals) {}

    public function request(Document $document, string $action, string $reason, User $actor, ?string $replacementId = null): DocumentStatusChange
    {
        if (! in_array($action, ['REVOKE', 'REPLACE', 'CANCEL'], true)) {
            throw ValidationException::withMessages(['action' => 'Action must be REVOKE, REPLACE or CANCEL.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A reason (5+ characters) is required.']);
        }
        if (! in_array($document->status, DocumentRegister::CURRENT_STATUSES, true) || ! in_array($document->document_origin, DocumentRegister::ISSUED_ORIGINS, true)) {
            throw ValidationException::withMessages(['document' => 'Only a current issued document can be revoked, replaced or cancelled.']);
        }
        if ($action === 'REPLACE') {
            $replacement = $replacementId ? Document::find($replacementId) : null;
            if (! $replacement || $replacement->id === $document->id || $replacement->policy_id !== $document->policy_id || ! in_array($replacement->status, DocumentRegister::CURRENT_STATUSES, true)) {
                throw ValidationException::withMessages(['replacement_document_id' => 'The replacement must be another current document of the same policy.']);
            }
        }
        if (DocumentStatusChange::where('document_id', $document->id)->where('status', 'PENDING')->exists()) {
            throw ValidationException::withMessages(['document' => 'A status change is already pending for this document.']);
        }
        return DB::transaction(function () use ($document, $action, $reason, $replacementId, $actor): DocumentStatusChange {
            $change = DocumentStatusChange::create(['document_id' => $document->id, 'action' => $action, 'reason' => $reason, 'replacement_document_id' => $replacementId, 'status' => 'PENDING', 'requested_by' => $actor->id]);
            $this->approvals->open($actor, $this->approvalInput($change, $document));
            $this->audit->record('document.status_change.requested', 'document', $document->id, ['action' => $action, 'change_id' => $change->id], $reason);

            return $change;
        });
    }

    public function approve(DocumentStatusChange $change, User $actor, ?string $note = null): Document
    {
        return DB::transaction(function () use ($change, $actor, $note): Document {
            $change = DocumentStatusChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $this->decidable($change, $actor);
            $doc = Document::whereKey($change->document_id)->lockForUpdate()->firstOrFail();
            $approval = $this->approvals->recordDecision($this->approvalFor($change), $actor, 'APPROVED', $note);
            if (! $this->approvals->isApproved($approval)) {
                return $doc; // further approval levels required by the matrix
            }
            if (! in_array($doc->status, DocumentRegister::CURRENT_STATUSES, true)) {
                throw ValidationException::withMessages(['document' => 'The document is no longer current.']);
            }
            $to = ['REVOKE' => 'REVOKED', 'REPLACE' => 'REPLACED', 'CANCEL' => 'CANCELLED'][$change->action];
            $doc->update(['status' => $to, 'status_reason' => $change->reason, 'status_changed_at' => now(), 'status_changed_by' => $actor->id,
                'superseded_by_document_id' => $change->action === 'REPLACE' ? $change->replacement_document_id : $doc->superseded_by_document_id]);
            if ($change->action === 'REPLACE') {
                Document::whereKey($change->replacement_document_id)->update(['supersedes_document_id' => $doc->id]);
            }
            // REQ-DUP-005: the legacy digital attestation is the engine's PROOF_OF_COVER document; keep its
            // policy_certificates row (serial / QR verification) in step so both paths answer the same.
            if (in_array($to, ['REVOKED', 'CANCELLED'], true) && ($serial = $doc->provenance['certificate_serial'] ?? null)) {
                \App\Models\PolicyCertificate::where('serial_number', $serial)->where('status', 'VALID')
                    ->update(['status' => 'VOID', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => mb_substr('Document '.$to.': '.$change->reason, 0, 255)]);
            }
            $change->update(['status' => 'APPROVED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->record('document.'.strtolower($to), 'document', $doc->id, ['change_id' => $change->id, 'requested_by' => $change->requested_by], $change->reason);

            return $doc->refresh();
        });
    }

    public function reject(DocumentStatusChange $change, User $actor, string $note): DocumentStatusChange
    {
        return DB::transaction(function () use ($change, $actor, $note): DocumentStatusChange {
            $change = DocumentStatusChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $this->decidable($change, $actor);
            $this->approvals->recordDecision($this->approvalFor($change), $actor, 'REJECTED', $note);
            $change->update(['status' => 'REJECTED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->record('document.status_change.rejected', 'document', $change->document_id, ['change_id' => $change->id], $note);

            return $change->refresh();
        });
    }

    public function expireDue(): int
    {
        return Document::whereIn('status', ['VALID', 'ISSUED'])->whereNotNull('valid_until')->where('valid_until', '<', now())
            ->update(['status' => 'EXPIRED', 'status_changed_at' => now(), 'status_reason' => 'Validity period ended']);
    }

    private function decidable(DocumentStatusChange $change, User $actor): void
    {
        if ($change->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }
        if ($change->requested_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
        }
    }

    private function approvalFor(DocumentStatusChange $change): ApprovalRequest
    {
        return $this->approvals->forSource('document_status_changes', $change->id,
            fn () => [$change->requested_by, $this->approvalInput($change, Document::find($change->document_id))]);
    }

    private function approvalInput(DocumentStatusChange $change, ?Document $document): array
    {
        return ['action_code' => 'document.status_change', 'subject_type' => 'document', 'subject_id' => $change->document_id,
            'source_table' => 'document_status_changes', 'source_id' => $change->id, 'reason' => $change->reason,
            'tenant_id' => $document?->tenant_id ?? null, 'payload' => ['action' => $change->action, 'replacement_document_id' => $change->replacement_document_id]];
    }
}
