<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Mobile\Capabilities\CapabilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /mobile/capabilities (ARCH-004/005): {data: {data_scope, modules: {<module>: {view, actions[]}}}} for the caller
 * in the current tenant. Advisory for the app's navigation; every endpoint still authorizes on its own.
 */
final class MobileCapabilitiesController
{
    public function __invoke(Request $request, CapabilityResolver $capabilities): JsonResponse
    {
        return response()->json(['data' => $capabilities->modules($request->user())]);
    }
}
