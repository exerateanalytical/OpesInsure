<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Identity;

use App\Application\Identity\MobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app's own auth adapter (BATCH_1_CLAUDE_HANDOFF.md): OTP-based
 * login issuing a short-lived Passport personal access token plus a
 * rotating refresh token, and workspace bootstrap. See MobileAuthService
 * for the actual security logic — this controller is intentionally thin.
 */
final class MobileAuthController
{
    public function requestOtp(Request $request, MobileAuthService $service): JsonResponse
    {
        $data = $request->validate(['phone_e164' => ['required', 'regex:/^\+[1-9]\d{7,14}$/']]);

        return response()->json(['data' => $service->requestOtp($data['phone_e164'], $request->ip())]);
    }

    public function verifyOtp(Request $request, MobileAuthService $service): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => 'required|uuid',
            'code' => 'required|string|size:6',
            'device_fingerprint' => 'required|string|max:128',
            'device_name' => 'nullable|string|max:100',
            'platform' => 'nullable|string|max:24',
        ]);

        $result = $service->verifyOtp(
            $data['challenge_id'],
            $data['code'],
            $data['device_fingerprint'],
            $request->ip(),
            $data['device_name'] ?? null,
            $data['platform'] ?? null,
        );

        return response()->json(['data' => $result], 201);
    }

    public function session(Request $request, MobileAuthService $service): JsonResponse
    {
        return response()->json(['data' => $service->session($request->user())]);
    }

    public function refresh(Request $request, MobileAuthService $service): JsonResponse
    {
        $data = $request->validate(['refresh_token' => 'required|string']);

        return response()->json(['data' => $service->refresh($data['refresh_token'])]);
    }

    public function logout(Request $request, MobileAuthService $service): JsonResponse
    {
        $data = $request->validate(['refresh_token' => 'nullable|string']);

        $service->logout($request->user(), $request->user()->token()->id, $data['refresh_token'] ?? null);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
