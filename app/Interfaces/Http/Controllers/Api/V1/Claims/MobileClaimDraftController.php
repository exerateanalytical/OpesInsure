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
        [$claim, $created] = $drafts->submit($draft, $request->user(), $this->tenant());

        return response()->json(['data' => $claim], $created ? 201 : 200);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
