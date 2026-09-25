<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Approvals\ApprovalService;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * approval_matrix.change — the matrix is itself maker-checker governed (SCF §58). The request payload holds
 * {rule_id?: uuid, changes: {...}}; the change is applied only once the request is fully APPROVED.
 */
final class ApprovalMatrixChangeHandler implements ApprovalHandler
{
    public const EDITABLE = ['min_amount', 'max_amount', 'currency', 'product_id', 'insurer_tenant_id', 'branch_code', 'checker_permission', 'checker_roles',
        'required_approvals', 'requires_maker_checker', 'exclude_subject_parties', 'priority', 'status', 'effective_from', 'effective_to', 'description'];

    public function __construct(private readonly ApprovalService $approvals) {}

    /** Maker side: propose a new rule (rule_id null) or a change to an existing one. */
    public static function propose(ApprovalService $approvals, User $maker, ?string $ruleId, array $changes, string $reason, ?string $actionCode = null): ApprovalRequest
    {
        $changes = array_intersect_key($changes, array_flip(self::EDITABLE));
        $rule = $ruleId ? ApprovalMatrixRule::findOrFail($ruleId) : null;
        if (! $rule && ! $actionCode) {
            throw ValidationException::withMessages(['action_code' => 'A new matrix rule needs an action code.']);
        }

        return $approvals->open($maker, ['action_code' => 'approval_matrix.change', 'subject_type' => 'approval_matrix_rule', 'subject_id' => $rule?->id,
            'reason' => $reason, 'tenant_id' => $rule?->tenant_id ?? rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false),
            'payload' => ['rule_id' => $rule?->id, 'action_code' => $rule?->action_code ?? $actionCode, 'changes' => $changes,
                'previous' => $rule ? array_intersect_key($rule->toArray(), $changes) : null]]);
    }

    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note) {
            $req = $this->approvals->recordDecision($request, $actor, 'APPROVED', $note);
            if ($req->status !== 'APPROVED') {
                return;
            }
            $p = $req->payload;
            if ($p['rule_id'] ?? null) {
                ApprovalMatrixRule::whereKey($p['rule_id'])->firstOrFail()->update($p['changes']);
            } else {
                $catalogue = \App\Application\Approvals\ApprovalActionCatalogue::get($p['action_code']);
                ApprovalMatrixRule::create([...$p['changes'], 'tenant_id' => $req->tenant_id, 'action_code' => $p['action_code'],
                    'workflow' => $catalogue['workflow'], 'category' => $catalogue['category'], 'source_refs' => $catalogue['sources']]);
            }
        });
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->approvals->recordDecision($request, $actor, 'REJECTED', $note);
    }
}
