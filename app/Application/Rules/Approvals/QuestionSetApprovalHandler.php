<?php

declare(strict_types=1);

namespace App\Application\Rules\Approvals;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\QuestionSetCatalogue;
use App\Models\ApprovalRequest;
use App\Models\User;

/** Generic approval inbox → QuestionSetCatalogue (question_set.approve). */
final class QuestionSetApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly QuestionSetCatalogue $catalogue) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->catalogue->decide(QuestionSet::findOrFail($request->source_id), $actor, true, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->catalogue->decide(QuestionSet::findOrFail($request->source_id), $actor, false, $note);
    }
}
