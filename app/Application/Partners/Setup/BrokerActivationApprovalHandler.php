<?php

declare(strict_types=1);

namespace App\Application\Partners\Setup;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Approvals\ApprovalService;
use App\Application\Partners\Setup\Models\PartnerSetup;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Inbox → broker setup (broker_setup.activate, REQ-SET-003). Approval and activation commit together. */
final class BrokerActivationApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ApprovalService $approvals, private readonly PartnerSetupService $setups) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note) {
            $req = $this->approvals->recordDecision($request, $actor, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->setups->transition(PartnerSetup::findOrFail($req->subject_id), 'activate', $actor, $note ?? 'Approved in approval inbox');
            }
        });
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note) {
            $this->approvals->recordDecision($request, $actor, 'REJECTED', $note);
            $setup = PartnerSetup::findOrFail($request->subject_id);
            if ($setup->status === 'TESTING' && $setup->approval_request_id === $request->id) {
                $this->setups->transition($setup, 'reject_activation', $actor, $note);
            }
        });
    }
}
