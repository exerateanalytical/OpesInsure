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
    public const PHONE_RULE = ['required', 'regex:/^\+[1-9]\d{7,14}$/'];

    public const DEVICE_RULES = [
        'device_fingerprint' => 'required|string|max:128',
        'device_name' => 'nullable|string|max:100',
        'platform' => 'nullable|string|max:24',
    ];

    public function requestOtp(Request $request, MobileAuthService $service): JsonResponse
    {
        self::normalizePhone($request);
        $data = $request->validate(['phone_e164' => self::PHONE_RULE, 'channel' => 'nullable|in:whatsapp,sms']);

        return response()->json(['data' => $service->requestOtp($data['phone_e164'], $request->ip(), $data['channel'] ?? null)]);
    }

    /** POST auth/mobile/password-login: phone + password, same payload as OTP verify. */
    public function passwordLogin(Request $request, MobileAuthService $service): JsonResponse
    {
        self::normalizePhone($request);
        self::normalizeDevice($request);

        $data = $request->validate(['phone_e164' => self::PHONE_RULE, 'password' => 'required|string|max:128', ...self::DEVICE_RULES]);

        $result = $service->passwordLogin($data['phone_e164'], $data['password'], $data['device_fingerprint'], $request->ip(), $data['device_name'] ?? null, $data['platform'] ?? null);

        return response()->json(['data' => $result], 201);
    }

    /** POST auth/mobile/password/forgot: sends a reset code (always 200, never reveals whether the phone exists). */
    public function forgotPassword(Request $request, MobileAuthService $service): JsonResponse
    {
        self::normalizePhone($request);
        $data = $request->validate(['phone_e164' => self::PHONE_RULE, 'channel' => 'nullable|in:whatsapp,sms']);

        return response()->json(['data' => $service->requestOtp($data['phone_e164'], $request->ip(), $data['channel'] ?? null, 'PASSWORD_RESET')]);
    }

    /** POST auth/mobile/password/reset: code + new password, signs in. */
    public function resetPassword(Request $request, MobileAuthService $service): JsonResponse
    {
        self::normalizePhone($request);
        self::normalizeDevice($request);

        $data = $request->validate([
            'phone_e164' => self::PHONE_RULE,
            'code' => 'required|string|size:6',
            'challenge_id' => 'nullable|uuid',
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'sometimes|nullable|same:password',
            'device_fingerprint' => 'nullable|string|max:128',
            'device_name' => 'nullable|string|max:100',
            'platform' => 'nullable|string|max:24',
        ]);

        $result = $service->resetPassword(
            $data['phone_e164'], $data['code'], $data['password'], $data['challenge_id'] ?? null,
            $data['device_fingerprint'] ?? 'unnamed-'.hash('sha256', $data['phone_e164'].$request->userAgent()),
            $request->ip(), $data['device_name'] ?? null, $data['platform'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Accepts `phone` or `phone_e164` (phone_e164 wins when both are sent)
     * and normalises common local forms to E.164: spaces/dashes/brackets are
     * dropped, a 00 prefix becomes +, and a bare 9-digit Cameroon number or a
     * 237... number without + gets +237 / +.
     */
    public static function normalizePhone(Request $request): void
    {
        $raw = $request->input('phone_e164') ?? $request->input('phone');

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $phone = preg_replace('/[\s\-().]/', '', $raw);

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (! str_starts_with($phone, '+')) {
            $phone = strlen($phone) === 9 ? '+237'.$phone : '+'.$phone;
        }

        $request->merge(['phone_e164' => $phone]);
    }

    /**
     * The shipped Expo client sends the device as a nested object
     * ({device: {fingerprint, name, platform}}); older callers send it flat.
     * Both work.
     */
    public static function normalizeDevice(Request $request): void
    {
        $device = $request->input('device');

        if (is_array($device)) {
            $request->merge(array_filter([
                'device_fingerprint' => $device['fingerprint'] ?? null,
                'device_name' => $device['name'] ?? null,
                'platform' => $device['platform'] ?? null,
            ], static fn ($v) => $v !== null));
        }
    }

    public function verifyOtp(Request $request, MobileAuthService $service): JsonResponse
    {
        self::normalizeDevice($request);

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
