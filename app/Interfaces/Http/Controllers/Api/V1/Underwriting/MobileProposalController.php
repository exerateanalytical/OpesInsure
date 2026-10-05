<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Underwriting;

use App\Application\Underwriting\MobileProposalService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Support\MobileList;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileProposalController
{
    /** GET /mobile/proposals — the caller's own proposals, newest activity first. */
    public function index(Request $request, MobileProposalService $service): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 20), 100));
        $filter = $request->validate(['quote_offer_id' => 'sometimes|nullable|uuid']);
        $page = $service->list($request->user(), app(TenantContext::class)->id(), $perPage, $filter['quote_offer_id'] ?? null);

        // Rows carry required_documents (checklist progress) while open: no per-row checklist call in the app.
        return response()->json(MobileList::fromPaginator($page, fn (Proposal $p) => $service->presentRow($p)));
    }

    /**
     * GET /mobile/proposals/{proposal} — the caller's own proposal in the list projection, including `counter_offer`
     * (revised premium/tax/fee/total and the underwriter's notes) while it is COUNTEROFFERED, so the app can show
     * the revised price before the customer accepts. 404 for anyone else's proposal.
     */
    public function show(string $proposal, Request $request, MobileProposalService $service): JsonResponse
    {
        $p = $service->owned($proposal, $request->user(), app(TenantContext::class)->id());
        $p->load(['offer.product', 'offer.carrier.party', 'offer.quote']);

        return response()->json(['data' => $service->present($p)]);
    }

    /** POST /mobile/proposals/{proposal}/counteroffer/{accept|decline} */
    public function counterOffer(string $proposal, string $answer, Request $request, MobileProposalService $service): JsonResponse
    {
        abort_unless(in_array($answer, ['accept', 'decline'], true), 404);
        $p = $service->respondToCounterOffer($proposal, $answer, $request->user(), app(TenantContext::class)->id());
        $p->load(['offer.product', 'offer.carrier.party', 'offer.quote']);

        return response()->json(['data' => $service->present($p)]);
    }
}
