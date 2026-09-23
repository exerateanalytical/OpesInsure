<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** account/* screens: devices, profile, locale, notification preferences, push tokens. */
final class MobileAccountController
{
    private const PREFERENCE_DEFAULTS = ['push' => true, 'sms' => true, 'email' => false, 'renewals' => true, 'claims' => true, 'payments' => true];

    public function devices(Request $request): JsonResponse
    {
        $current = $request->header('X-Device-Fingerprint');
        $devices = UserDevice::where('user_id', $request->user()->id)->whereNull('revoked_at')->orderByDesc('last_seen_at')->get();
        $latest = $devices->first()?->id;

        return response()->json(['data' => $devices->map(fn (UserDevice $d) => [
            'id' => $d->id,
            'name' => $d->name ?: ucfirst((string) $d->platform).' device',
            'platform' => $d->platform,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'current' => $current ? $d->device_fingerprint === $current : $d->id === $latest,
        ])->values()]);
    }

    public function revokeDevice(string $device, Request $request, AuditWriter $audit): JsonResponse
    {
        $row = UserDevice::where('user_id', $request->user()->id)->findOrFail($device);
        $row->update(['revoked_at' => now()]);
        DB::table('mobile_refresh_tokens')->where('device_id', $row->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $audit->record('mobile.device.revoked', 'user_device', $row->id, []);

        return response()->json(['data' => ['id' => $row->id, 'revoked' => true]]);
    }

    public function updateProfile(Request $request, AuditWriter $audit): JsonResponse
    {
        $data = $request->validate(['full_name' => 'required|string|min:3|max:120', 'email' => 'nullable|email:rfc|max:190']);
        $user = $request->user();
        $user->forceFill(['full_name' => $data['full_name'], 'email' => $data['email'] ?? $user->email])->save();
        if ($user->party) {
            $user->party->update(['display_name' => $data['full_name']]);
        }
        $audit->record('mobile.profile.updated', 'user', $user->id, ['fields' => array_keys($data)]);

        return response()->json(['data' => ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone_e164' => $user->phone_e164, 'locale' => $user->locale]]);
    }

    public function setLocale(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => 'required|in:en,fr']);
        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return response()->json(['data' => ['locale' => $data['locale']]]);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json(['data' => array_merge(self::PREFERENCE_DEFAULTS, $request->user()->notification_preferences ?? [])]);
    }

    public function saveNotificationPreferences(Request $request): JsonResponse
    {
        $data = $request->validate(array_map(fn () => 'required|boolean', self::PREFERENCE_DEFAULTS));
        $request->user()->forceFill(['notification_preferences' => $data])->save();

        return response()->json(['data' => $data]);
    }

    public function registerPush(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:255', 'platform' => 'required|string|max:16']);
        DB::table('user_push_tokens')->updateOrInsert(['token' => $data['token']], [
            'id' => DB::table('user_push_tokens')->where('token', $data['token'])->value('id') ?? (string) Str::uuid(),
            'user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['registered' => true]], 201);
    }
}
