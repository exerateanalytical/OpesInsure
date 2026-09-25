<?php

declare(strict_types=1);

namespace App\Application\Customers\Matching;

use App\Application\Approvals\ApprovalHandler;
use App\Application\MasterData\MasterDataMergeService;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * One approval action for every duplicate-entity merge (entity.merge, ICE gap 15 — one canonical action, not one per
 * entity). The generic inbox resolves a single handler per action, so this routes by source table:
 * party_merges → PartyMergeService, anything else (master_data_merge_requests) → MasterDataMergeService.
 */
final class EntityMergeApprovalRouter implements ApprovalHandler
{
    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->target($request)->approve($request, $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->target($request)->reject($request, $actor, $note);
    }

    private function target(ApprovalRequest $request): ApprovalHandler
    {
        return $request->source_table === PartyMergeService::SOURCE_TABLE ? app(PartyMergeService::class) : app(MasterDataMergeService::class);
    }
}
