<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Runtime;

use App\Application\Runtime\MobileRuntimeService;
use App\Application\Runtime\MobileTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated mobile-app runtime surface: the cold-start
 * bootstrap call and the crash/error/usage telemetry sink. See
 * MobileRuntimeService and MobileTelemetryService for the actual logic —
 * both are intentionally reachable before login (a crash on the splash
 * screen, or a force-update decision, happens before any session exists).
 */
final class MobileRuntimeController
{
    public function bootstrap(Request $request, MobileRuntimeService $service): JsonResponse
    {
        $data = $request->validate([
            'version' => 'nullable|string|max:32',
            'build' => 'nullable|string|max:32',
            'channel' => 'nullable|string|max:32',
        ]);

        return response()->json(['data' => $service->bootstrap($data['version'] ?? null)]);
    }

    public function telemetry(Request $request, MobileTelemetryService $service): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:'.implode(',', config('mobile_runtime.telemetry.events'))],
            'correlation_id' => 'required|string|max:100',
            'app_version' => 'required|string|max:32',
            'release_channel' => 'required|string|max:32',
            'attributes' => 'nullable|array',
        ]);

        $event = $service->ingest($data, $request->ip());

        return response()->json(['data' => ['accepted' => true, 'id' => $event->id]], 201);
    }
}
