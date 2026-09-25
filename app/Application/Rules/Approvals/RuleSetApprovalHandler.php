<?php

declare(strict_types=1);

namespace App\Application\Rules\Approvals;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleSetService;
use App\Models\ApprovalRequest;
use App\Models\User;

/** Generic approval inbox → RuleSetService (rule_set.approve). */
final class RuleSetApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly RuleSetService $sets) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->sets->decide(RuleSet::findOrFail($request->source_id), $actor, true, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->sets->decide(RuleSet::findOrFail($request->source_id), $actor, false, $note);
    }
}
