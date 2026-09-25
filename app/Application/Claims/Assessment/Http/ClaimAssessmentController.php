<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment\Http;

use App\Application\Claims\Assessment\AssessmentDecisionReadiness;
use App\Application\Claims\Assessment\ClaimAssessmentService;
use App\Application\Claims\Assessment\ClaimInvestigationService;
use App\Application\Claims\Assessment\Models\ClaimAssessment;
use App\Application\Claims\Assessment\Models\ClaimInvestigation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CLM-010 — claim assessments (recommendations) and investigations. */
final class ClaimAssessmentController
{
    public function index(string $claim, AssessmentDecisionReadiness $readiness): JsonResponse
    {
        $c = $this->claim($claim);

        return response()->json(['data' => [
            'assessments' => ClaimAssessment::where('claim_id', $c->id)->orderBy('created_at')->get(),
            'investigations' => ClaimInvestigation::where('claim_id', $c->id)->with('indicators')->orderBy('created_at')->get(),
            'decision_blocker' => $readiness->blockingReason($c),
        ]]);
    }

    public function store(Request $r, string $claim, ClaimAssessmentService $s): JsonResponse
    {
        $d = $r->validate([
            'heads' => 'required|array|min:1|max:50',
            'heads.*.head_code' => 'required|string|max:64',
            'heads.*.recommended_minor' => 'required|integer|min:0',
            'heads.*.claimed_minor' => 'nullable|integer|min:0',
            'heads.*.note' => 'nullable|string|max:1000',
            'rationale' => 'required|string|min:10|max:10000',
            'adjuster_report_document_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $s->record($this->claim($claim), $d, $r->user())], 201);
    }

    public function accept(Request $r, string $assessment, ClaimAssessmentService $s): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $s->accept($this->assessment($assessment), $r->user(), $d['note'] ?? null)]);
    }

    public function reject(Request $r, string $assessment, ClaimAssessmentService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $s->reject($this->assessment($assessment), $r->user(), $d['reason'])]);
    }

    public function openInvestigation(Request $r, string $claim, ClaimInvestigationService $s): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'reason' => 'required|string|min:5|max:5000']);

        return response()->json(['data' => $s->open($this->claim($claim), $d['reason_code'], $d['reason'], $r->user())], 201);
    }

    public function attachIndicators(Request $r, string $investigation, ClaimInvestigationService $s): JsonResponse
    {
        $d = $r->validate([
            'indicators' => 'required|array|min:1|max:100',
            'indicators.*.indicator_id' => 'required|string|max:64',
            'indicators.*.indicator_code' => 'nullable|string|max:64',
            'indicators.*.snapshot' => 'nullable|array',
        ]);

        return response()->json(['data' => $s->attachIndicators($this->investigation($investigation), $d['indicators'], $r->user())], 201);
    }

    public function findings(Request $r, string $investigation, ClaimInvestigationService $s): JsonResponse
    {
        $d = $r->validate(['findings' => 'required|string|min:5|max:20000']);

        return response()->json(['data' => $s->recordFindings($this->investigation($investigation), $d['findings'], $r->user())]);
    }

    public function conclude(Request $r, string $investigation, ClaimInvestigationService $s): JsonResponse
    {
        $d = $r->validate(['outcome' => ['required', Rule::in(ClaimInvestigationService::OUTCOMES)], 'summary' => 'required|string|min:5|max:5000']);

        return response()->json(['data' => $s->conclude($this->investigation($investigation), $d['outcome'], $d['summary'], $r->user())->load('indicators')]);
    }

    private function claim(string $id): Claim
    {
        return Claim::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    private function assessment(string $id): ClaimAssessment
    {
        return ClaimAssessment::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    private function investigation(string $id): ClaimInvestigation
    {
        return ClaimInvestigation::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
