<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy;

use App\Application\Approvals\ApprovalHandler;
use App\Models\ApprovalRequest;
use App\Models\Import\ImportBatch;
use App\Models\User;

/** Approval inbox → LegacyMigrationPipeline (legacy_migration.commit on import_batches). */
final class LegacyMigrationApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly LegacyMigrationPipeline $pipeline) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->pipeline->approve(ImportBatch::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->pipeline->reject(ImportBatch::findOrFail($request->source_id), $actor, $note);
    }
}
