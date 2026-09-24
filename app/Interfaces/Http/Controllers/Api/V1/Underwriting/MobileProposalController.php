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
        $page = $service->list($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(MobileList::fromPaginator($page, fn (Proposal $p) => $service->present($p)));
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
