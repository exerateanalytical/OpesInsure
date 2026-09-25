<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Underwriting;

use App\Application\Identity\OwnershipScope;
use App\Application\Policies\PolicyIssuabilityService;
use App\Application\Underwriting\ProposalService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 6D proposal lifecycle endpoints (routes/proposals.php). Proposers only resolve their own proposals
 * (OwnershipScope, as ProposalController); staff actions carry their permission on the route.
 */
final class ProposalLifecycleController
{
    public function __construct(private readonly OwnershipScope $own, private readonly ProposalService $service) {}

    /** GET proposals/{p}/checklist — questions, declarations, documents, KYC, cover terms, blockers, next events. */
    public function checklist(Request $r, string $proposal): JsonResponse
    {
        return response()->json(['data' => $this->service->checklist($this->proposal($r, $proposal), $r->user())]);
    }

    /** POST proposals/{p}/declarations {codes: [..]} */
    public function declare(Request $r, string $proposal): JsonResponse
    {
        $d = $r->validate(['codes' => 'required|array|min:1', 'codes.*' => 'string|max:64', 'channel' => 'sometimes|in:WEB,MOBILE,API,AGENT,BRANCH']);
        $p = $this->proposal($r, $proposal);
        foreach (array_unique($d['codes']) as $code) {
            $p = $this->service->declare($p, strtoupper($code), $r->user(), $d['channel'] ?? 'API', ['ip' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }

        return response()->json(['data' => $this->service->checklist($p, $r->user())['declarations']], 201);
    }

    /** PUT proposals/{p}/cover-terms — effective-date rule, duration, instalment plan (REQ-PRP-005). */
    public function coverTerms(Request $r, string $proposal): JsonResponse
    {
        $d = $r->validate([
            'effective_rule' => 'sometimes|string|max:24', 'start_date' => 'sometimes|nullable|date',
            'duration' => 'sometimes|array', 'duration.unit' => 'required_with:duration|in:DAY,MONTH,CUSTOM', 'duration.value' => 'sometimes|integer|min:1|max:366',
            'instalment_plan' => 'sometimes|string|max:16', 'custom_instalments' => 'sometimes|array', 'custom_instalments.*' => 'integer|min:1',
        ]);

        return response()->json(['data' => $this->service->selectCoverTerms($this->proposal($r, $proposal), $d, $r->user())->cover_terms]);
    }

    /** POST proposals/{p}/information-requests (underwriter) {items: [{code?, description}], message?} */
    public function requestInformation(Request $r, string $proposal): JsonResponse
    {
        $d = $r->validate(['items' => 'required|array|min:1|max:50', 'items.*.code' => 'sometimes|string|max:64', 'items.*.description' => 'required|string|min:3|max:1000', 'message' => 'sometimes|nullable|string|max:4000']);

        return response()->json(['data' => $this->service->requestInformation($this->proposal($r, $proposal), $d['items'], $d['message'] ?? null, $r->user())]);
    }

    /** POST proposals/{p}/resubmit {response?} */
    public function resubmit(Request $r, string $proposal): JsonResponse
    {
        $d = $r->validate(['response' => 'sometimes|nullable|string|max:4000']);

        return response()->json(['data' => $this->service->resubmit($this->proposal($r, $proposal), $r->user(), $d['response'] ?? null)], 202);
    }

    /** POST proposals/{p}/withdraw {reason?} */
    public function withdraw(Request $r, string $proposal): JsonResponse
    {
        $d = $r->validate(['reason' => 'sometimes|nullable|string|max:2000']);

        return response()->json(['data' => $this->service->withdraw($this->proposal($r, $proposal), $r->user(), $d['reason'] ?? null)]);
    }

    /** GET proposals/{p}/submissions — the immutable submitted snapshots. */
    public function submissions(Request $r, string $proposal): JsonResponse
    {
        return response()->json(['data' => $this->proposal($r, $proposal)->submissions()->get()]);
    }

    /** GET proposals/{p}/issuability (staff) — POLICY_ISSUABLE evaluation with blockers (REQ-PRP-004). */
    public function issuability(Request $r, string $proposal, PolicyIssuabilityService $issuability): JsonResponse
    {
        return response()->json(['data' => $issuability->evaluate($this->proposal($r, $proposal))]);
    }

    private function proposal(Request $r, string $id): Proposal
    {
        return $this->own->apply(Proposal::where('tenant_id', app(TenantContext::class)->id()), $r->user())->findOrFail($id);
    }
}
