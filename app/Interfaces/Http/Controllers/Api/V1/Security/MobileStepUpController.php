<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Security;

use App\Application\Security\MobileStepUpService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step-up challenge/verify pair — see MobileStepUpService for the security
 * logic. Both routes sit inside the same ['auth:api','tenant','json.api']
 * group as the rest of the tenant-scoped mobile API: step-up elevates an
 * already-authenticated session, it does not establish one.
 */
final class MobileStepUpController
{
    public function request(Request $request, MobileStepUpService $service): JsonResponse
    {
        $data = $request->validate(['purpose' => 'required|string|max:64']);

        return response()->json(['data' => $service->request($request->user(), $data['purpose'], $request->ip())]);
    }

    public function verify(Request $request, MobileStepUpService $service): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => 'required|uuid',
            'purpose' => 'required|string|max:64',
            'code' => 'required|string|size:6',
        ]);

        $result = $service->verify(
            $request->user(),
            app(TenantContext::class)->id(),
            $data['challenge_id'],
            $data['purpose'],
            $data['code'],
        );

        return response()->json(['data' => $result], 201);
    }
}
