<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\MobileClaimDraftService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Customer claim drafts — see MobileClaimDraftService. */
final class MobileClaimDraftController
{
    public function index(Request $request, MobileClaimDraftService $drafts): JsonResponse
    {
        return response()->json(['data' => $drafts->list($request->user(), $this->tenant())]);
    }

    public function store(Request $request, MobileClaimDraftService $drafts): JsonResponse
    {
        $data = $request->validate(MobileClaimDraftService::draftRules());

        return response()->json(['data' => $drafts->create($data, $request->user(), $this->tenant())], 201);
    }

    public function show(string $draft, Request $request, MobileClaimDraftService $drafts): JsonResponse
    {
        return response()->json(['data' => $drafts->show($draft, $request->user(), $this->tenant())]);
    }

    public function update(string $draft, Request $request, MobileClaimDraftService $drafts): JsonResponse
    {
        $data = $request->validate(MobileClaimDraftService::draftRules());

        return response()->json(['data' => $drafts->update($draft, $data, $request->user(), $this->tenant())]);
    }

    public function destroy(string $draft, Request $request, MobileClaimDraftService $drafts): Response
    {
        $drafts->delete($draft, $request->user(), $this->tenant());

        return response()->noContent();
    }

    public function submit(string $draft, Request $request, MobileClaimDraftService $drafts): JsonResponse
    {
        // Optional body: {declaration_confirmed: true} records the customer's declaration on the filed claim.
        $body = $request->validate(['declaration_confirmed' => 'sometimes|boolean']);
        [$claim, $created, $evidence] = $drafts->submit($draft, $request->user(), $this->tenant(), (bool) ($body['declaration_confirmed'] ?? false));

        // `evidence`: files saved on the draft that were attached (or are in their security check) / could not be.
        return response()->json(['data' => $claim, 'evidence' => $evidence], $created ? 201 : 200);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
