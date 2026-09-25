<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Application\Audit\AuditWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RBAC-005 / REQ-RBAC-006 — THE approval engine (WF-081). Every maker-checker action in the platform
 * opens an approval_requests row here; the matrix decides levels / checker permission / roles; SoD decides who
 * may not check. Domain services own the domain row and effect, and call:
 *   open()            when the maker submits (returns AUTO_APPROVED when the matrix waives maker-checker)
 *   recordDecision()  inside their own transaction when a checker decides (throws on maker-checker / SoD / matrix)
 * The generic inbox / API calls approve()/reject(), which delegate to a registered ApprovalHandler for
 * source-linked actions, so there is exactly one decision path.
 */
final class ApprovalService
{
    /** Built-in domain handlers (migrated callers). Extra ones via registerHandler(). */
    public const DEFAULT_HANDLERS = [
        'document.status_change' => Handlers\DocumentStatusChangeApprovalHandler::class,
        'engine.override' => Handlers\EngineOverrideApprovalHandler::class,
        'configuration.publish' => Handlers\ConfigurationChangeApprovalHandler::class,
        'approval_matrix.change' => Handlers\ApprovalMatrixChangeHandler::class,
    ];

    /** @var array<string, class-string<ApprovalHandler>> */
    private array $handlers = self::DEFAULT_HANDLERS;

    public function __construct(
        private readonly ApprovalMatrixResolver $matrix,
        private readonly SegregationOfDuties $sod,
        private readonly AuditWriter $audit,
    ) {}

    /** @param class-string<ApprovalHandler> $handler */
    public function registerHandler(string $action, string $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    public function hasHandler(string $action): bool
    {
        return isset($this->handlers[$action]);
    }

    /**
     * @param  array{action_code: string, subject_type: string, subject_id?: string|null, source_table?: string|null, source_id?: string|null,
     *               amount?: float|string|null, currency?: string|null, product_id?: string|null, insurer_tenant_id?: string|null,
     *               branch_code?: string|null, payload?: array|null, reason?: string|null, excluded_user_ids?: list<string>, tenant_id?: string|null}  $input
     */
    public function open(User|string $maker, array $input): ApprovalRequest
    {
        $makerId = $maker instanceof User ? $maker->id : $maker;
        foreach (['action_code', 'subject_type'] as $k) {
            if (blank($input[$k] ?? null)) {
                throw ValidationException::withMessages([$k => "{$k} is required."]);
            }
        }
        $tenant = array_key_exists('tenant_id', $input) ? $input['tenant_id'] : $this->tenant();
        $ctx = ['amount' => $input['amount'] ?? null, 'product_id' => $input['product_id'] ?? null, 'insurer_tenant_id' => $input['insurer_tenant_id'] ?? null,
            'branch_code' => $input['branch_code'] ?? null, 'tenant_id' => $tenant];
        $rule = $this->matrix->resolve($input['action_code'], $ctx);
        $makerChecker = $rule?->requires_maker_checker ?? true;
        $excluded = array_values(array_unique(array_filter($input['excluded_user_ids'] ?? [])));

        if (! empty($input['source_table']) && ! empty($input['source_id'])
            && ApprovalRequest::where('source_table', $input['source_table'])->where('source_id', $input['source_id'])->exists()) {
            throw ValidationException::withMessages(['source' => 'An approval request already exists for this record.']);
        }

        $req = ApprovalRequest::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'action_code' => $input['action_code'], 'matrix_rule_id' => $rule?->id,
            'subject_type' => $input['subject_type'], 'subject_id' => $input['subject_id'] ?? null,
            'source_table' => $input['source_table'] ?? null, 'source_id' => $input['source_id'] ?? null,
            'amount' => $input['amount'] ?? null, 'currency' => $input['currency'] ?? null,
            'context' => array_filter(['product_id' => $ctx['product_id'], 'insurer_tenant_id' => $ctx['insurer_tenant_id'], 'branch_code' => $ctx['branch_code']]),
            'payload' => $input['payload'] ?? null, 'reason' => $input['reason'] ?? null,
            'status' => $makerChecker ? 'PENDING' : 'AUTO_APPROVED', 'decided_at' => $makerChecker ? null : now(),
            'required_approvals' => $rule?->required_approvals ?? 1, 'requested_by' => $makerId, 'excluded_user_ids' => $excluded,
            'correlation_id' => substr((string) (rescue(fn () => request()->header('X-Request-Id'), null, false) ?: Str::uuid()), 0, 64),
        ]);
        $this->audit->record($makerChecker ? 'approval.requested' : 'approval.auto_approved', 'approval_request', $req->id, ['subject_type' => $req->subject_type, 'subject_id' => $req->subject_id, 
            'approval_id' => $req->id, 'action_code' => $req->action_code, 'matrix_rule_id' => $rule?->id, 'amount' => $req->amount, 'required_approvals' => $req->required_approvals,
        ], $req->reason, ['approval_id' => $req->id]);

        return $req;
    }

    /** Returns the request linked to a domain row, opening one for legacy rows created before the approval engine. */
    public function forSource(string $sourceTable, string $sourceId, ?callable $legacy = null): ApprovalRequest
    {
        $req = ApprovalRequest::where('source_table', $sourceTable)->where('source_id', $sourceId)->first();
        if ($req) {
            return $req;
        }
        if (! $legacy) {
            throw ValidationException::withMessages(['approval' => 'No approval request exists for this record.']);
        }
        [$maker, $input] = $legacy();

        return $this->open($maker, [...$input, 'source_table' => $sourceTable, 'source_id' => $sourceId]);
    }

    /** Entry point for the generic inbox / API. */
    public function approve(ApprovalRequest $request, User $actor, ?string $note = null): ApprovalRequest
    {
        if ($handler = $this->handlerFor($request)) {
            $handler->approve($request, $actor, $note);

            return $request->refresh();
        }

        return $this->recordDecision($request, $actor, 'APPROVED', $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): ApprovalRequest
    {
        if (mb_strlen(trim($note)) < 3) {
            throw ValidationException::withMessages(['note' => 'A rejection needs a note.']);
        }
        if ($handler = $this->handlerFor($request)) {
            $handler->reject($request, $actor, $note);

            return $request->refresh();
        }

        return $this->recordDecision($request, $actor, 'REJECTED', $note);
    }

    /**
     * Enforces maker-checker, SoD and the matrix, then records one checker decision. Status becomes APPROVED only
     * once required_approvals distinct checkers approved; any rejection is final. Callers apply their domain effect
     * only when the returned status is APPROVED.
     */
    public function recordDecision(ApprovalRequest $request, User $actor, string $decision, ?string $note = null): ApprovalRequest
    {
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision must be APPROVED or REJECTED.']);
        }

        return DB::transaction(function () use ($request, $actor, $decision, $note): ApprovalRequest {
            $req = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertCanDecide($req, $actor);
            DB::table('approval_decisions')->insert(['id' => (string) Str::uuid(), 'approval_request_id' => $req->id, 'decided_by' => $actor->id,
                'decision' => $decision, 'note' => $note === null ? null : mb_substr($note, 0, 500), 'created_at' => now()]);
            $count = $req->approvals_count + ($decision === 'APPROVED' ? 1 : 0);
            $final = $decision === 'REJECTED' || $count >= $req->required_approvals;
            $req->update(['approvals_count' => $count] + ($final ? ['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(),
                'decision_note' => $note === null ? null : mb_substr($note, 0, 500)] : []));
            $this->audit->record('approval.'.($final ? strtolower($decision) : 'level_approved'), 'approval_request', $req->id, ['subject_type' => $req->subject_type, 'subject_id' => $req->subject_id, 
                'approval_id' => $req->id, 'action_code' => $req->action_code, 'decided_by' => $actor->id, 'requested_by' => $req->requested_by,
                'level' => $count, 'required' => $req->required_approvals,
            ], $note, ['approval_id' => $req->id]);

            return $req->refresh();
        });
    }

    public function cancel(ApprovalRequest $request, User $actor, string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $req = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($req->status !== 'PENDING' || $req->requested_by !== $actor->id) {
                throw ValidationException::withMessages(['approval' => 'Only the requester can withdraw a pending request.']);
            }
            $req->update(['status' => 'CANCELLED', 'decision_note' => mb_substr($reason, 0, 500)]);
            $this->audit->record('approval.cancelled', 'approval_request', $req->id, ['subject_type' => $req->subject_type, 'subject_id' => $req->subject_id, 'approval_id' => $req->id], $reason);

            return $req->refresh();
        });
    }

    /** @return list<string> reasons the user may not decide (empty = may decide) */
    public function blockers(ApprovalRequest $req, User $actor): array
    {
        $b = $req->status === 'PENDING' ? [] : ["This request is already {$req->status}."];
        $b = [...$b, ...$this->sod->violations($req, $actor->id)];
        $rule = $req->rule;
        if ($rule?->checker_permission && ! $actor->hasPermission($rule->checker_permission)) {
            $b[] = "Approval matrix: requires permission {$rule->checker_permission}.";
        }
        if ($rule && ! empty($rule->checker_roles)) {
            $roles = $actor->memberships()->where('status', 'ACTIVE')->when($req->tenant_id, fn ($q) => $q->where('tenant_id', $req->tenant_id))->pluck('role_code')->all();
            if (! array_intersect($roles, $rule->checker_roles)) {
                $b[] = 'Approval matrix: requires one of the roles '.implode(', ', $rule->checker_roles).'.';
            }
        }

        return $b;
    }

    public function canDecide(ApprovalRequest $req, User $actor): bool
    {
        return $this->blockers($req, $actor) === [];
    }

    public function assertCanDecide(ApprovalRequest $req, User $actor): void
    {
        if ($b = $this->blockers($req, $actor)) {
            throw ValidationException::withMessages(['approval' => $b[0]]);
        }
    }

    public function isApproved(ApprovalRequest $req): bool
    {
        return in_array($req->status, ['APPROVED', 'AUTO_APPROVED'], true);
    }

    private function handlerFor(ApprovalRequest $req): ?ApprovalHandler
    {
        return isset($this->handlers[$req->action_code]) ? app($this->handlers[$req->action_code]) : null;
    }

    private function tenant(): ?string
    {
        return rescue(fn () => app(TenantContext::class)->id(), null, false);
    }
}
