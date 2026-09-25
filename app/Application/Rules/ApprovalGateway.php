<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Application\Approvals\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\User;

/** Rule / question set maker-checker goes through THE approval engine (REQ-RBAC-005) — no local approve logic. */
final class ApprovalGateway
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function open(User $maker, string $action, string $subjectType, string $subjectId, string $sourceTable, ?string $productId, array $payload): ApprovalRequest
    {
        return $this->approvals->open($maker, [
            'action_code' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'source_table' => $sourceTable, 'source_id' => $subjectId, 'product_id' => $productId, 'payload' => $payload,
        ]);
    }

    public function decide(string $requestId, User $actor, bool $approve, ?string $note): ApprovalRequest
    {
        $req = ApprovalRequest::findOrFail($requestId);

        return $this->approvals->recordDecision($req, $actor, $approve ? 'APPROVED' : 'REJECTED', $note);
    }

    public function isApproved(ApprovalRequest $req): bool
    {
        return $this->approvals->isApproved($req);
    }
}
