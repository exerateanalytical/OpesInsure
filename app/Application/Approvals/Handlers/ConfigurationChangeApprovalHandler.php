<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Configuration\ConfigurationGovernanceService;
use App\Models\ApprovalRequest;
use App\Models\ConfigurationChangeSet;
use App\Models\User;

/** Inbox → ConfigurationGovernanceService (configuration.publish). */
final class ConfigurationChangeApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ConfigurationGovernanceService $config) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->config->approve(ConfigurationChangeSet::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->config->reject(ConfigurationChangeSet::findOrFail($request->source_id), $actor, $note);
    }
}
