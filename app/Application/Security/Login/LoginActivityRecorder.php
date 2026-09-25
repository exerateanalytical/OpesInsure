<?php

declare(strict_types=1);

namespace App\Application\Security\Login;

use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * REQ-SEC-001 login activity: one row per successful sign-in with device and
 * hashed IP, plus anomaly flags. NEW_DEVICE is raised when a user who already
 * had a device signs in from an unseen one. IMPOSSIBLE_TRAVEL is only ever
 * evaluated when BOTH this and the previous login carry edge geolocation
 * (config security_centre.login.geo_headers) — without geo data it is skipped,
 * never guessed.
 *
 * Never throws: recording must not block a sign-in.
 */
final class LoginActivityRecorder
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    /** @return list<string> anomaly flags */
    public function record(User $user, string $method, ?string $deviceId, string $deviceFingerprint, ?string $deviceName, ?string $platform, bool $newDevice, string $ip, ?Request $request = null): array
    {
        try {
            // Savepoint: a failure here must not poison the caller's sign-in transaction.
            return DB::transaction(fn () => $this->doRecord($user, $method, $deviceId, $deviceFingerprint, $deviceName, $platform, $newDevice, $ip, $request));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<string> */
    private function doRecord(User $user, string $method, ?string $deviceId, string $deviceFingerprint, ?string $deviceName, ?string $platform, bool $newDevice, string $ip, ?Request $request): array
    {
        $request ??= request();
        [$lat, $lng, $country] = $this->geo($request);
        $flags = [];
        if ($newDevice && DB::table('user_devices')->where('user_id', $user->id)->where('id', '!=', $deviceId)->exists()) {
            $flags[] = 'NEW_DEVICE';
        }
        if ($lat !== null && $lng !== null) {
            $prev = DB::table('login_activities')->where('user_id', $user->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderByDesc('occurred_at')->first();
            if ($prev && $this->impossible((float) $prev->latitude, (float) $prev->longitude, $lat, $lng, now()->diffInSeconds($prev->occurred_at, true))) {
                $flags[] = 'IMPOSSIBLE_TRAVEL';
            }
        }

        $id = (string) Str::uuid();
        DB::table('login_activities')->insert([
            'id' => $id, 'user_id' => $user->id, 'method' => $method, 'device_id' => $deviceId,
            'device_fingerprint_hash' => hash('sha256', $deviceFingerprint), 'device_name' => $deviceName ? mb_substr($deviceName, 0, 120) : null,
            'platform' => $platform ? mb_substr($platform, 0, 24) : null, 'ip_hash' => hash('sha256', $ip),
            'user_agent_hash' => $request?->userAgent() ? hash('sha256', (string) $request->userAgent()) : null,
            'country_code' => $country, 'latitude' => $lat, 'longitude' => $lng, 'new_device' => $newDevice,
            'anomaly_flags' => json_encode($flags), 'occurred_at' => now(),
        ]);

        if ($flags !== []) {
            DB::table('security_events')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'LOGIN_ANOMALY', 'severity' => in_array('IMPOSSIBLE_TRAVEL', $flags, true) ? 'WARNING' : 'NOTICE',
                'ip_hash' => hash('sha256', $ip), 'user_agent_hash' => null, 'metadata' => json_encode(['login_activity_id' => $id, 'flags' => $flags]), 'occurred_at' => now()]);
            $this->outbox->record('security.login.anomaly_detected', 'user', $user->id, ['login_activity_id' => $id, 'flags' => $flags]);
        }

        return $flags;
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} */
    private function geo(?Request $request): array
    {
        $h = config('security_centre.login.geo_headers', []);
        $read = fn (?string $name) => ($request && $name) ? $request->header($name) : null;
        $lat = $read($h['latitude'] ?? null);
        $lng = $read($h['longitude'] ?? null);
        $country = $read($h['country'] ?? null);
        $valid = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;

        return [$valid ? (float) $lat : null, $valid ? (float) $lng : null, is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null];
    }

    private function impossible(float $lat1, float $lng1, float $lat2, float $lng2, float $seconds): bool
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $km = 2 * $r * asin(min(1.0, sqrt($a)));
        if ($km < 100) {
            return false; // city-scale moves / geo-IP jitter are never flagged
        }
        $hours = max($seconds, 60) / 3600;

        return $km / $hours > (int) config('security_centre.login.impossible_travel_kmh', 900);
    }
}
