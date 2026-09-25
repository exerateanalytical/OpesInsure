<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Approvals;

use App\Application\Approvals\ApprovalActionCatalogue;
use App\Application\Approvals\ApprovalMatrixResolver;
use App\Application\Approvals\ApprovalService;
use App\Application\Configuration\ConfigurationGovernanceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalRequest;
use App\Models\ConfigurationChangeSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-RBAC-005/006, REQ-SET-005 — approval inbox, matrix, configuration change sets (WF-081, ESR ADM-029). */
final class ApprovalController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $r): JsonResponse
    {
        $rows = $this->scoped()
            ->when($r->query('status', 'PENDING'), fn ($q, $s) => $s === 'ALL' ? $q : $q->where('status', $s))
            ->when($r->query('action_code'), fn ($q, $a) => $q->where('action_code', $a))
            ->latest()->limit(max(1, min((int) $r->query('limit', 50), 200)))->get();
        $user = $r->user();

        return response()->json(['data' => $rows->map(fn (ApprovalRequest $a) => [...$a->toArray(), 'can_decide' => $this->approvals->canDecide($a, $user)])]);
    }

    public function show(Request $r, string $approval): JsonResponse
    {
        $a = $this->scoped()->findOrFail($approval);

        return response()->json(['data' => [...$a->toArray(), 'decisions' => $a->decisions()->orderBy('created_at')->get(), 'blockers' => $this->approvals->blockers($a, $r->user())]]);
    }

    public function decide(Request $r, string $approval, string $decision): JsonResponse
    {
        $d = $r->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']]);
        $a = $this->scoped()->findOrFail($approval);
        $a = $decision === 'approve' ? $this->approvals->approve($a, $r->user(), $d['note'] ?? null) : $this->approvals->reject($a, $r->user(), $d['note']);

        return response()->json(['data' => $a]);
    }

    public function cancel(Request $r, string $approval): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->approvals->cancel($this->scoped()->findOrFail($approval), $r->user(), $d['reason'])]);
    }

    public function actions(): JsonResponse
    {
        return response()->json(['data' => collect(ApprovalActionCatalogue::ACTIONS)
            ->map(fn ($a, $code) => ['action_code' => $code, ...$a, 'has_handler' => $this->approvals->hasHandler($code)])->values()]);
    }

    public function matrix(Request $r): JsonResponse
    {
        $tenant = app(TenantContext::class)->id();

        return response()->json(['data' => ApprovalMatrixRule::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))
            ->when($r->query('action_code'), fn ($q, $a) => $q->where('action_code', $a))->orderBy('action_code')->orderBy('priority')->get()]);
    }

    public function resolve(Request $r, ApprovalMatrixResolver $resolver): JsonResponse
    {
        $d = $r->validate(['action_code' => ['required', Rule::in(array_keys(ApprovalActionCatalogue::ACTIONS))], 'amount' => 'nullable|numeric',
            'product_id' => 'nullable|uuid', 'insurer_tenant_id' => 'nullable|uuid', 'branch_code' => 'nullable|string|max:64']);

        return response()->json(['data' => $resolver->resolve($d['action_code'], [...$d, 'tenant_id' => app(TenantContext::class)->id()])]);
    }

    public function configurationChanges(): JsonResponse
    {
        return response()->json(['data' => ConfigurationChangeSet::where('tenant_id', app(TenantContext::class)->id())->latest()->limit(100)->get()]);
    }

    public function draftConfigurationChange(Request $r, ConfigurationGovernanceService $config): JsonResponse
    {
        $d = $r->validate(['config_type' => 'required|string|max:64', 'config_key' => 'required|string|max:128', 'proposed_value' => 'required|array',
            'reason' => 'required|string|min:5', 'effective_from' => 'nullable|date']);

        return response()->json(['data' => $config->draft($r->user(), $d['config_type'], $d['config_key'], $d['proposed_value'], $d['reason'], $d['effective_from'] ?? null)], 201);
    }

    public function transitionConfigurationChange(Request $r, ConfigurationGovernanceService $config, string $change, string $step): JsonResponse
    {
        $cs = ConfigurationChangeSet::where('tenant_id', app(TenantContext::class)->id())->findOrFail($change);
        $cs = $step === 'submit' ? $config->submit($cs, $r->user()) : $config->publish($cs, $r->user());

        return response()->json(['data' => $cs]);
    }

    private function scoped()
    {
        $tenant = app(TenantContext::class)->id();

        return ApprovalRequest::query()->where(fn ($q) => $q->where('tenant_id', $tenant)->orWhereNull('tenant_id'));
    }
}
