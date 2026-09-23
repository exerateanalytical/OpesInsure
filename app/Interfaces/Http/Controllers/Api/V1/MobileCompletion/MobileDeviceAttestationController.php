<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Device-risk assessment (security/device-status). Play Integrity / App
 * Attest verdict verification against Google/Apple is not wired to a
 * project yet, so the assessment is nonce-bound and records the client's
 * self-report; the resulting action is ALLOW unless the client reports a
 * compromised runtime, in which case sensitive actions are LIMITed. The
 * decision is server-issued and audited, matching config/mobile_runtime.php's
 * "device risk" contract, and can be tightened without an app change.
 */
final class MobileDeviceAttestationController
{
    public function nonce(Request $request): JsonResponse
    {
        $nonce = Str::random(48);
        Cache::put("attest-nonce:{$request->user()->id}:{$nonce}", true, now()->addMinutes(10));

        return response()->json(['data' => ['nonce' => $nonce, 'expires_at' => now()->addMinutes(10)->toIso8601String()]], 201);
    }

    public function assess(Request $request, AuditWriter $audit): JsonResponse
    {
        $data = $request->validate([
            'nonce' => 'required|string|max:64', 'platform' => 'required|in:ANDROID,IOS', 'provider' => 'required|in:PLAY_INTEGRITY,APP_ATTEST,UNAVAILABLE_MANAGED_RUNTIME',
            'token' => 'nullable|string', 'signals' => 'sometimes|array',
        ]);
        $key = "attest-nonce:{$request->user()->id}:{$data['nonce']}";
        abort_unless(Cache::pull($key), 422, 'Attestation nonce is invalid or expired.');
        $signals = $data['signals'] ?? [];
        $reasons = [];
        if ($data['provider'] === 'UNAVAILABLE_MANAGED_RUNTIME') {
            $reasons[] = 'ATTESTATION_UNAVAILABLE';
        }
        if (! empty($signals['rooted']) || ! empty($signals['jailbroken'])) {
            $reasons[] = 'COMPROMISED_RUNTIME';
        }
        if (! empty($signals['emulator'])) {
            $reasons[] = 'EMULATOR';
        }
        $action = in_array('COMPROMISED_RUNTIME', $reasons, true) ? 'LIMIT' : 'ALLOW';
        $id = (string) Str::uuid();
        $audit->record('security.device.assessed', 'user', $request->user()->id, ['assessment_id' => $id, 'action' => $action, 'reasons' => $reasons, 'platform' => $data['platform'], 'provider' => $data['provider']]);

        return response()->json(['data' => ['assessment_id' => $id, 'action' => $action, 'reasons' => $reasons ?: ['NO_RISK_SIGNALS'], 'expires_at' => now()->addHours(12)->toIso8601String()]], 201);
    }
}
