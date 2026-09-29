<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring\Http;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Operations\Monitoring\AlertDispatcher;
use App\Application\Operations\Monitoring\ReleaseId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * S12: GET /status — JSON for uptime monitors. Readable with the MONITORING_STATUS_TOKEN (Bearer or ?token=) or by an
 * authenticated platform administrator (auth:api). No tenant data, no personal data. 200 when the database answers
 * and no critical alert is firing, else 503 so a plain HTTP monitor flags it. /up (Laravel health) stays public.
 */
final class StatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->authorised($request), 401);

        $checks = [];
        try {
            $start = hrtime(true);
            DB::select('select 1');
            $checks['database'] = ['status' => 'ok', 'latency_ms' => (int) round((hrtime(true) - $start) / 1e6)];
        } catch (Throwable) {
            $checks['database'] = ['status' => 'down'];
        }
        if ($checks['database']['status'] === 'ok') {
            $checks['scheduler'] = rescue(function (): array {
                $last = DB::table('ops_scheduler_heartbeats')->where('name', 'scheduler')->value('last_beat_at');
                $age = $last === null ? null : now()->getTimestamp() - \Carbon\Carbon::parse($last)->getTimestamp();

                return ['status' => $age !== null && $age <= (int) config('operations.scheduler.heartbeat_stale_seconds', 180) ? 'ok' : 'stale', 'age_seconds' => $age];
            }, ['status' => 'unknown'], false);
            $checks['queue'] = rescue(fn (): array => [
                'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->whereNull('reserved_at')->count() : null,
                'failed_last_hour' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count(),
            ], ['status' => 'unknown'], false);
            $checks['errors'] = rescue(fn (): array => [
                'open' => DB::table('error_events')->where('status', 'OPEN')->count(),
                'last_hour' => (int) DB::table('error_event_counts')->where('bucket_start', '>=', now()->subHour())->sum('total'),
            ], ['status' => 'unknown'], false);
        }
        $alerts = AlertDispatcher::firing();
        $critical = array_filter($alerts, fn ($a) => ($a['severity'] ?? null) === 'critical');
        $status = $checks['database']['status'] !== 'ok' ? 'down' : ($critical !== [] ? 'down' : ($alerts !== [] || ($checks['scheduler']['status'] ?? 'ok') !== 'ok' ? 'degraded' : 'ok'));

        return response()->json([
            'status' => $status, 'release' => ReleaseId::current(), 'environment' => app()->environment(), 'time' => now()->toIso8601String(),
            'checks' => $checks, 'alerts' => $alerts,
        ], $status === 'down' ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    private function authorised(Request $request): bool
    {
        $token = (string) config('monitoring.status_token');
        $given = (string) ($request->bearerToken() ?? $request->query('token', ''));
        if ($token !== '' && $given !== '' && hash_equals($token, $given)) {
            return true;
        }
        $user = rescue(fn () => auth()->guard('api')->user() ?? auth()->user(), null, false);

        return $user !== null && (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformAdmin($user), false, false);
    }
}
