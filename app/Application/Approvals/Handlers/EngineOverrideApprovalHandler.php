<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Overrides\OverrideService;
use App\Models\ApprovalRequest;
use App\Models\User;

/** Inbox → OverrideService (engine.override, engine_overrides). */
final class EngineOverrideApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly OverrideService $overrides) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->overrides->approve($request->source_id, $actor->id, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->overrides->reject($request->source_id, $actor->id, $note);
    }
}
