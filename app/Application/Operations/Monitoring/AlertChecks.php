<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

use App\Application\Documents\Scanning\DocumentScanQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * S12: the 5-minute checks behind ops:alerts. Each returns
 *   ['firing' => bool, 'severity' => critical|warning, 'params' => [...]]  — params feed monitoring.alerts.<key>.body
 * or null when it could not be evaluated (a table missing, the database down for a database-backed check): an
 * unevaluated check never fires and never clears — the `database` check covers an unreachable database.
 */
final class AlertChecks
{
    public const KEYS = ['database', 'error_spike', 'failed_jobs', 'queue_backlog', 'scheduler_heartbeat', 'payment_auth_failures', 'carrier_api_failures', 'scanner_backlog', 'disk_space'];

    /** @return array<string, array{firing:bool, severity:string, params:array<string,scalar>}|null> */
    public function run(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            try {
                $out[$key] = $this->{lcfirst(str_replace('_', '', ucwords($key, '_')))}();
            } catch (Throwable) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    private function threshold(string $key): int|float
    {
        return config('monitoring.alerts.thresholds.'.$key);
    }

    private function window(): int
    {
        return max(1, (int) config('monitoring.alerts.window_minutes', 15));
    }

    private static function result(bool $firing, string $severity, array $params): array
    {
        return ['firing' => $firing, 'severity' => $severity, 'params' => $params];
    }

    private function database(): array
    {
        $signals = MonitoringSignals::recent(MonitoringSignals::DB_CONNECTION_ERROR, $this->window());
        try {
            DB::select('select 1');
            $up = true;
        } catch (Throwable) {
            $up = false;
        }

        return self::result(! $up || $signals['total'] >= $this->threshold('db_connection_errors'), 'critical',
            ['errors' => $signals['total'], 'minutes' => $this->window(), 'reachable' => $up ? 'yes' : 'no']);
    }

    private function errorSpike(): ?array
    {
        if (! Schema::hasTable('error_event_counts')) {
            return null;
        }
        $ts = now()->getTimestamp() - 300;
        $count = (int) DB::table('error_event_counts')->where('bucket_start', '>=', now()->setTimestamp($ts - ($ts % 300)))->sum('total');

        return self::result($count >= $this->threshold('error_spike'), 'warning', ['count' => $count, 'threshold' => $this->threshold('error_spike')]);
    }

    private function failedJobs(): ?array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes($this->window()))->count();

        return self::result($count >= $this->threshold('failed_jobs'), 'warning', ['count' => $count, 'minutes' => $this->window()]);
    }

    private function queueBacklog(): ?array
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }
        $pending = DB::table('jobs')->whereNull('reserved_at');
        $count = (clone $pending)->count();
        $oldest = (clone $pending)->min('available_at');
        $age = $oldest === null ? 0 : max(0, intdiv(now()->getTimestamp() - (int) $oldest, 60));

        return self::result($count >= $this->threshold('queue_backlog') || $age >= $this->threshold('queue_age_minutes'), 'warning',
            ['count' => $count, 'age' => $age]);
    }

    private function schedulerHeartbeat(): ?array
    {
        if (! Schema::hasTable('ops_scheduler_heartbeats')) {
            return null;
        }
        $last = DB::table('ops_scheduler_heartbeats')->where('name', 'scheduler')->value('last_beat_at');
        $stale = (int) config('operations.scheduler.heartbeat_stale_seconds', 180);
        $age = $last === null ? null : now()->getTimestamp() - \Carbon\Carbon::parse($last)->getTimestamp();

        return self::result($age === null || $age > $stale, 'critical', ['age' => $age === null ? 'never' : (string) intdiv($age, 60)]);
    }

    private function paymentAuthFailures(): array
    {
        $s = MonitoringSignals::recent(MonitoringSignals::PAYMENT_AUTH_FAILURE, $this->window());

        return self::result($s['total'] >= $this->threshold('payment_auth_failures'), 'critical',
            ['count' => $s['total'], 'providers' => implode(', ', array_keys($s['sources'])) ?: '-', 'minutes' => $this->window()]);
    }

    private function carrierApiFailures(): ?array
    {
        if (! Schema::hasTable('carrier_api_calls')) {
            return null;
        }
        $rows = DB::table('carrier_api_calls as c')->leftJoin('carriers as k', 'k.id', '=', 'c.carrier_id')
            ->where('c.created_at', '>=', now()->subMinutes($this->window()))
            ->groupBy('c.carrier_id', 'k.brand_short_name', 'k.legal_name')
            ->selectRaw("coalesce(k.brand_short_name, k.legal_name, cast(c.carrier_id as varchar)) as carrier, count(*) as total,
                sum(case when c.outcome = 'OK' or (c.outcome = 'HTTP_ERROR' and coalesce(c.http_status, 0) not in (401, 403)) then 0 else 1 end) as failed")
            ->get();
        $bad = [];
        $worst = 0.0;
        foreach ($rows as $r) {
            $rate = $r->total > 0 ? $r->failed / $r->total : 0.0;
            if ($r->total >= $this->threshold('carrier_min_calls') && $rate >= $this->threshold('carrier_failure_rate')) {
                $bad[] = $r->carrier.' '.$r->failed.'/'.$r->total;
                $worst = max($worst, $rate);
            }
        }

        return self::result($bad !== [], 'critical', ['carriers' => implode(', ', $bad) ?: '-', 'rate' => (int) round($worst * 100), 'minutes' => $this->window()]);
    }

    private function scannerBacklog(): ?array
    {
        if (! Schema::hasTable('document_scan_queue')) {
            return null;
        }
        $q = DB::table('document_scan_queue')->where('status', DocumentScanQueue::SCAN_UNAVAILABLE);
        $count = (clone $q)->count();
        $oldest = (clone $q)->min('created_at');
        $age = $oldest === null ? 0 : (int) max(0, intdiv(now()->getTimestamp() - \Carbon\Carbon::parse($oldest)->getTimestamp(), 60));

        return self::result($count > 0 && ($count >= $this->threshold('scanner_backlog') || $age >= $this->threshold('scanner_age_minutes')), 'warning',
            ['count' => $count, 'age' => $age]);
    }

    private function diskSpace(): ?array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }
        $pct = round($free / $total * 100, 1);

        return self::result($pct < $this->threshold('disk_free_percent'), 'critical', ['percent' => $pct, 'free_gb' => round($free / 1073741824, 1)]);
    }
}
