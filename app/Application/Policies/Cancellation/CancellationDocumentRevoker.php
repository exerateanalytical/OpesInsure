<?php

declare(strict_types=1);

namespace App\Application\Policies\Cancellation;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Engine\DocumentRegister;
use App\Models\Document;
use App\Models\DocumentStatusChange;
use App\Models\Policy;
use App\Models\PolicyCertificate;
use App\Models\User;

/**
 * REQ-CAN-001 / WF-045: on an approved cancellation every current issued document of the policy (schedule,
 * attestation, certificate, endorsements) is REVOKED — never deleted; verification then answers REVOKED.
 * The cancellation approval is itself the maker-checker decision, so each revocation is written as an already
 * APPROVED document_status_changes row (requested_by = cancellation requester, decided_by = approver), mirroring
 * DocumentStatusService::approve, and legacy policy_certificates rows are voided.
 */
final class CancellationDocumentRevoker
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @return list<string> */
    public function currentDocumentIds(string $policyId): array
    {
        return Document::where('policy_id', $policyId)
            ->whereIn('status', DocumentRegister::CURRENT_STATUSES)
            ->whereIn('document_origin', DocumentRegister::ISSUED_ORIGINS)
            ->pluck('id')->all();
    }

    /** @param list<string> $documentIds */
    public function revoke(Policy $policy, array $documentIds, string $requestedBy, User $approver, string $reason): int
    {
        $count = 0;
        foreach (Document::whereIn('id', $documentIds)->lockForUpdate()->get() as $doc) {
            if (! in_array($doc->status, DocumentRegister::CURRENT_STATUSES, true)) {
                continue;
            }
            DocumentStatusChange::where('document_id', $doc->id)->where('status', 'PENDING')
                ->update(['status' => 'REJECTED', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => 'Superseded by policy cancellation.']);
            $doc->update(['status' => 'REVOKED', 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by' => $approver->id]);
            DocumentStatusChange::create([
                'document_id' => $doc->id, 'action' => 'REVOKE', 'reason' => $reason, 'status' => 'APPROVED',
                'requested_by' => $requestedBy, 'decided_by' => $approver->id, 'decided_at' => now(),
                'decision_note' => 'Revoked by approved policy cancellation.',
            ]);
            $this->audit->record('document.revoked', 'document', $doc->id, ['policy_id' => $policy->id, 'cause' => 'POLICY_CANCELLATION'], $reason);
            $count++;
        }

        $count += PolicyCertificate::where('policy_id', $policy->id)->where('status', 'VALID')->update([
            'status' => 'VOID', 'voided_at' => now(), 'voided_by' => $approver->id, 'void_reason' => mb_substr($reason, 0, 255),
        ]);

        return $count;
    }
}
