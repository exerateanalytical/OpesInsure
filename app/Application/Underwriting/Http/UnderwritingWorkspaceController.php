<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Http;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Underwriting\UnderwritingCaseMachine;
use App\Application\Underwriting\UnderwritingDecisionService;
use App\Application\Underwriting\UnderwritingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\UnderwritingCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-UW-001…005 — underwriter workspace API (ESR UND): queue, case file (canonical state, evaluation, explainable
 * score, referrals, decisions), system evaluate, review steps and the WF-019 information request. Decisions keep using
 * POST underwriting/cases/{case}/decision (ProposalController::decide). Tenant- and carrier-scoped.
 */
final class UnderwritingWorkspaceController
{
    public function __construct(private readonly CarrierScopeResolver $scope) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['state' => 'sometimes|string|max:32', 'mine' => 'sometimes|boolean', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = $this->scoped($r)->with('proposal:id,proposal_number,status,party_id')->withCount(['referrals as open_referrals_count' => fn ($x) => $x->where('status', 'OPEN')])
            ->when(! empty($d['mine']), fn ($x) => $x->where('assigned_to', $r->user()->id))
            ->when(! isset($d['state']), fn ($x) => $x->where('status', '<>', 'DECIDED')->where('status', '<>', 'CANCELLED'))
            ->orderByRaw("CASE priority WHEN 'HIGH' THEN 0 ELSE 1 END")->orderBy('decision_due_at');
        $rows = $q->get()->map(fn (UnderwritingCase $c) => $this->row($c));
        if (isset($d['state'])) {
            $rows = $rows->where('state', strtoupper($d['state']))->values();
        }

        return response()->json(['data' => $rows->take((int) ($d['per_page'] ?? 50))->values()]);
    }

    public function show(Request $r, string $case): JsonResponse
    {
        $c = $this->find($r, $case)->load(['proposal', 'referrals', 'decisions']);

        return response()->json(['data' => $this->row($c) + [
            'risk_factors' => $c->risk_factors ?? [], 'rule_set_versions' => $c->rule_set_versions ?? [], 'engine_evaluation_id' => $c->engine_evaluation_id,
            'evaluated_at' => $c->evaluated_at?->toIso8601String(), 'information_request' => $c->proposal?->information_request,
            'referrals' => $c->referrals->map(fn ($t) => $t->only(['id', 'reason_code', 'status', 'severity', 'due_at', 'resolution_notes', 'resolved_at']))->values(),
            'decisions' => $c->decisions->sortBy('decided_at')->map(fn ($x) => $x->only(['id', 'decision', 'outcome', 'reason_code', 'notes', 'conditions', 'system_recommendation', 'engine_evaluation_id', 'decided_by', 'decided_at']))->values(),
            'available_events' => UnderwritingCaseMachine::availableEvents($c),
        ]]);
    }

    public function evaluate(Request $r, string $case, UnderwritingDecisionService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->evaluate($this->find($r, $case), $r->user())]);
    }

    public function startReview(Request $r, string $case, UnderwritingService $svc): JsonResponse
    {
        return response()->json(['data' => $this->row($svc->startReview($this->find($r, $case), $r->user()))]);
    }

    public function readyForDecision(Request $r, string $case, UnderwritingService $svc): JsonResponse
    {
        $d = $r->validate(['note' => 'sometimes|nullable|string|max:4000']);

        return response()->json(['data' => $this->row($svc->readyForDecision($this->find($r, $case), $r->user(), $d['note'] ?? null))]);
    }

    public function requestInformation(Request $r, string $case, UnderwritingService $svc, UnderwritingDecisionService $eval): JsonResponse
    {
        $d = $r->validate(['items' => 'sometimes|array|max:30', 'items.*.code' => 'required|string|max:64', 'items.*.description' => 'required|string|min:5|max:1000',
            'items.*.kind' => 'sometimes|string|max:24', 'items.*.mandatory' => 'sometimes|boolean', 'message' => 'sometimes|nullable|string|max:4000',
            'use_evaluation_items' => 'sometimes|boolean']);
        $c = $this->find($r, $case);
        $items = $d['items'] ?? [];
        if ($items === [] && ! empty($d['use_evaluation_items'])) {
            $items = $eval->evaluate($c, $r->user())['requested_items'];
        }

        return response()->json(['data' => $this->row($svc->requestInformation($c->refresh(), $items, $d['message'] ?? null, $r->user()))]);
    }

    private function row(UnderwritingCase $c): array
    {
        return [
            'id' => $c->id, 'proposal_id' => $c->proposal_id, 'proposal_number' => $c->proposal?->proposal_number, 'proposal_status' => $c->proposal?->status,
            'carrier_id' => $c->carrier_id, 'status' => $c->status, 'state' => UnderwritingCaseMachine::canonicalState($c), 'outcome' => $c->outcome,
            'priority' => $c->priority, 'assigned_to' => $c->assigned_to, 'decision_due_at' => $c->decision_due_at?->toIso8601String(), 'referral_reasons' => $c->referral_reasons ?? [],
            'recommendation' => $c->recommendation, 'risk_score' => $c->risk_score, 'risk_band' => $c->risk_band,
            'open_referrals_count' => $c->open_referrals_count ?? $c->referrals()->where('status', 'OPEN')->count(),
        ];
    }

    private function scoped(Request $r)
    {
        $tenant = app(TenantContext::class)->id();
        $carrier = $this->scope->carrierIdFor($r->user(), $tenant);

        return UnderwritingCase::query()->where('tenant_id', $tenant)->when($carrier !== null, fn ($q) => $q->where('carrier_id', $carrier));
    }

    private function find(Request $r, string $id): UnderwritingCase
    {
        return $this->scoped($r)->findOrFail($id);
    }
}
