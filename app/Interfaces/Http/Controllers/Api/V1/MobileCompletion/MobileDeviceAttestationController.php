<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Security\Attestation\DeviceAttestationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Device-risk assessment (security/device-status). The attestation token is
 * verified server-side through DeviceAttestationService (Play Integrity /
 * App Attest behind DeviceAttestationVerifier); an unconfigured verifier
 * yields UNVERIFIED, never PASS. The action is ALLOW unless the client
 * reports a compromised runtime or the verifier returns FAIL, in which case
 * sensitive actions are LIMITed. The
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

    public function assess(Request $request, AuditWriter $audit, DeviceAttestationService $attestation): JsonResponse
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
        // REQ-SEC-005: server-side verdict (UNVERIFIED unless a verifier is configured; never PASS by default).
        $verdict = $attestation->verify($request->user()->id, $data['platform'], $data['provider'], $data['token'] ?? null, $data['nonce']);
        if ($verdict->verdict === 'FAIL') {
            $reasons[] = 'ATTESTATION_FAILED';
        }
        $action = array_intersect(['COMPROMISED_RUNTIME', 'ATTESTATION_FAILED'], $reasons) !== [] ? 'LIMIT' : 'ALLOW';
        // B1/B6: the verdict is kept on the device row (PASS / FAIL / UNVERIFIED — an unconfigured verifier is never a pass).
        $user = $request->user();
        $fingerprint = $request->header('X-Device-Fingerprint');
        $device = \App\Models\UserDevice::where('user_id', $user->id)->whereNull('revoked_at')
            ->when($fingerprint, fn ($q) => $q->where('device_fingerprint', $fingerprint))->orderByDesc('last_seen_at')->first();
        $device?->forceFill(['attestation_status' => $verdict->verdict])->save();
        if ($verdict->verdict === 'FAIL') {
            app(\App\Application\Security\Login\LoginActivityRecorder::class)->recordEvent($user, 'ATTESTATION_FAILED', 'FAILED', $data['provider'], $device?->id, $request);
            app(\App\Application\Security\Alerts\SecurityAlerts::class)->send($user, 'INTEGRITY_FAILURE');
        }
        // CONFIG_REQUIRED: no verifier / no credentials for this provider on the server (security_centre.attestation.*).
        $configStatus = array_intersect(['NOT_CONFIGURED', 'ATTESTATION_UNAVAILABLE'], $verdict->reasons) !== [] ? 'CONFIG_REQUIRED' : 'CONFIGURED';
        $id = (string) Str::uuid();
        $audit->record('security.device.assessed', 'user', $request->user()->id, ['assessment_id' => $id, 'action' => $action, 'reasons' => $reasons, 'platform' => $data['platform'], 'provider' => $data['provider'], 'attestation_verdict' => $verdict->verdict]);

        return response()->json(['data' => ['assessment_id' => $id, 'action' => $action, 'reasons' => $reasons ?: ['NO_RISK_SIGNALS'], 'expires_at' => now()->addHours(12)->toIso8601String(),
            'attestation' => ['verdict' => $verdict->verdict, 'reasons' => $verdict->reasons, 'config_status' => $configStatus]]], 201);
    }
}
