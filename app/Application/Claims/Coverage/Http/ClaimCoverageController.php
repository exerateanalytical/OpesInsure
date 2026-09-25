<?php

declare(strict_types=1);

namespace App\Application\Claims\Coverage\Http;

use App\Application\Claims\Coverage\ClaimCoverageCheckService;
use App\Application\Claims\Coverage\CoverageAtLossEngine;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CLM-003 — coverage-at-loss check (ad hoc, and per claim as an immutable snapshot) + human resolution. */
final class ClaimCoverageController
{
    public function check(Request $r, CoverageAtLossEngine $engine): JsonResponse
    {
        $d = $r->validate([
            'policy_id' => 'required|uuid', 'loss_occurred_at' => 'required|date', 'reported_at' => 'nullable|date',
            'coverage_code' => 'nullable|string|max:64', 'facts' => 'sometimes|array',
        ]);
        $policy = Policy::where('tenant_id', $this->tenant())->findOrFail($d['policy_id']);

        return response()->json(['data' => $engine->evaluate(
            $policy, CarbonImmutable::parse($d['loss_occurred_at']), isset($d['reported_at']) ? CarbonImmutable::parse($d['reported_at']) : null,
            $d['coverage_code'] ?? null, $d['facts'] ?? [],
        )]);
    }

    public function index(string $claim, ClaimCoverageCheckService $s): JsonResponse
    {
        return response()->json(['data' => $s->forClaim($this->claim($claim))]);
    }

    public function store(Request $r, string $claim, ClaimCoverageCheckService $s): JsonResponse
    {
        $d = $r->validate(['coverage_code' => 'nullable|string|max:64', 'facts' => 'sometimes|array']);

        return response()->json(['data' => $s->checkClaim($this->claim($claim), $d['coverage_code'] ?? null, $d['facts'] ?? [], $r->user())], 201);
    }

    public function resolve(Request $r, string $claim, string $check, ClaimCoverageCheckService $s): JsonResponse
    {
        $d = $r->validate(['resolution' => ['required', Rule::in(ClaimCoverageCheckService::RESOLUTIONS)], 'note' => 'required|string|min:5|max:4000']);
        $c = $this->claim($claim);
        $row = $s->find($check);
        abort_if($row === null || $row->claim_id !== $c->id, 404);

        return response()->json(['data' => $s->resolve($row, $d['resolution'], $d['note'], $r->user())]);
    }

    private function claim(string $id): Claim
    {
        return Claim::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    private function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }
}
