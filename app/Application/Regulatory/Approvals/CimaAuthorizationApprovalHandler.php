<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Approvals;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Models\ApprovalRequest;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\User;

/** Approvals inbox → CimaAuthorizationService (cima.insurer_authorization.approve, insurer_regulatory_authorizations). */
final class CimaAuthorizationApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly CimaAuthorizationService $authorizations) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->authorizations->approve(InsurerRegulatoryAuthorization::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->authorizations->reject(InsurerRegulatoryAuthorization::findOrFail($request->source_id), $actor, $note);
    }
}
