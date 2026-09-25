<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * Domain adapter for a source-linked approval. When the generic inbox / API decides a request whose action has a
 * handler, ApprovalService delegates to the owning domain service so the domain effect and the approval record
 * commit together. The domain service calls ApprovalService::recordDecision() itself (no recursion).
 */
interface ApprovalHandler
{
    public function approve(ApprovalRequest $request, User $actor, ?string $note): void;

    public function reject(ApprovalRequest $request, User $actor, string $note): void;
}
