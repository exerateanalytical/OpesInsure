<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Approvals\ApprovalService;
use App\Application\CarrierOperations\Setup\Models\CarrierSetup;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Inbox → insurer setup (insurer_setup.activate, REQ-SET-002). Approval and activation commit together. */
final class InsurerActivationApprovalHandler implements ApprovalHandler
{
    public function __construct(private readonly ApprovalService $approvals, private readonly CarrierSetupService $setups) {}

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note) {
            $req = $this->approvals->recordDecision($request, $actor, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->setups->transition(CarrierSetup::findOrFail($req->subject_id), 'activate', $actor, $note ?? 'Approved in approval inbox');
            }
        });
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note) {
            $this->approvals->recordDecision($request, $actor, 'REJECTED', $note);
            $setup = CarrierSetup::findOrFail($request->subject_id);
            if ($setup->status === 'READY_FOR_APPROVAL' && $setup->approval_request_id === $request->id) {
                $this->setups->transition($setup, 'reject_approval', $actor, $note);
            }
        });
    }
}
