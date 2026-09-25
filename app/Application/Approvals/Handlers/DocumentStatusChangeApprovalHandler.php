<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Documents\Engine\DocumentStatusService;
use App\Models\ApprovalRequest;
use App\Models\DocumentStatusChange;
use App\Models\User;

/** Inbox → DocumentStatusService (document.status_change). */
final class DocumentStatusChangeApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly DocumentStatusService $documents) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->documents->approve(DocumentStatusChange::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->documents->reject(DocumentStatusChange::findOrFail($request->source_id), $actor, $note);
    }
}
