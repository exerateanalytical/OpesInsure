<?php

declare(strict_types=1);

namespace App\Application\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agent B6 — REQ-OPS-001 system health (ESR OPS-001..005). Every check is a live probe or a direct query; a check that
 * cannot run reports status UNKNOWN with the reason, never a fabricated OK. Overall = worst of the checks.
 */
final class SystemHealthService
{
    public const OK = 'OK';

    public const DEGRADED = 'DEGRADED';

    public const DOWN = 'DOWN';

    public const UNKNOWN = 'UNKNOWN';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const HEARTBEAT = 'scheduler';

    /** @return array{status:string, checked_at:string, checks:array<string, array<string, mixed>>} */
    public function summary(): array
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'storage' => $this->storage(),
            'mail' => $this->mail(),
            'sms' => $this->sms(),
        ];
        $rank = [self::OK => 0, self::NOT_CONFIGURED => 1, self::UNKNOWN => 1, self::DEGRADED => 2, self::DOWN => 3];
        $worst = self::OK;
        foreach ($checks as $c) {
            if ($rank[$c['status']] > $rank[$worst]) {
                $worst = $c['status'] === self::NOT_CONFIGURED ? self::DEGRADED : $c['status'];
            }
        }

        return ['status' => $worst, 'checked_at' => now()->toIso8601String(), 'checks' => $checks];
    }

    /** Record one scheduler heartbeat (called every minute by ops:heartbeat). */
    public function beat(string $name = self::HEARTBEAT): void
    {
        DB::table('ops_scheduler_heartbeats')->upsert(
            [['name' => $name, 'host' => gethostname() ?: null, 'last_beat_at' => now(), 'beats' => 1]],
            ['name'],
            ['host' => DB::raw('excluded.host'), 'last_beat_at' => DB::raw('excluded.last_beat_at'), 'beats' => DB::raw('ops_scheduler_heartbeats.beats + 1')],
        );
    }

    private function database(): array
    {
        try {
            $start = hrtime(true);
            DB::select('select 1');
            $ms = (int) round((hrtime(true) - $start) / 1e6);
            return ['status' => self::OK, 'driver' => DB::getDriverName(), 'latency_ms' => $ms, 'migrations_recorded' => DB::table('migrations')->count(),'pending_migrations' => $this->pendingMigrations()];
        } catch (Throwable $e) {
            return ['status' => self::DOWN, 'error' => Str::limit($e->getMessage(), 200)];
        }
    }

    private function pendingMigrations(): int
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn ($f) => basename($f, '.php'));
        $ran = DB::table('migrations')->pluck('migration')->flip();

        return $files->reject(fn ($m) => $ran->has($m))->count();
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default');
        $out = ['connection' => $connection, 'driver' => config("queue.connections.{$connection}.driver"), 'depth' => [], 'failed_jobs' => 0, 'failed_jobs_24h' => 0];
        try {
            foreach ((array) config('operations.queues.monitored', ['default']) as $queue) {
                $out['depth'][$queue] = Queue::connection($connection)->size($queue);
            }
            if (Schema::hasTable('failed_jobs')) {
                $out['failed_jobs'] = DB::table('failed_jobs')->count();
                $out['failed_jobs_24h'] = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            }
            $out['status'] = $out['failed_jobs_24h'] > 0 ? self::DEGRADED : self::OK;
            if ($out['driver'] === 'sync') {
                $out['note'] = 'sync driver: jobs run inline, no worker or backlog.';
            }
        } catch (Throwable $e) {
            $out['status'] = self::DOWN;
            $out['error'] = Str::limit($e->getMessage(), 200);
        }

        return $out;
    }

    private function scheduler(): array
    {
        $stale = (int) config('operations.scheduler.heartbeat_stale_seconds', 180);
        $row = Schema::hasTable('ops_scheduler_heartbeats') ? DB::table('ops_scheduler_heartbeats')->where('name', self::HEARTBEAT)->first() : null;
        if (! $row) {
            return ['status' => self::UNKNOWN, 'reason' => 'No scheduler heartbeat recorded yet (ops:heartbeat runs every minute under schedule:run).', 'stale_after_seconds' => $stale];
        }
        $age = (int) abs(now()->diffInSeconds(CarbonImmutable::parse($row->last_beat_at)));

        return ['status' => $age > $stale ? self::DOWN : self::OK, 'last_beat_at' => CarbonImmutable::parse($row->last_beat_at)->toIso8601String(), 'age_seconds' => $age, 'stale_after_seconds' => $stale, 'host' => $row->host, 'beats' => (int) $row->beats];
    }

    private function storage(): array
    {
        $disk = (string) config('filesystems.default');
        try {
            $probe = '.ops-health/'.Str::uuid().'.txt';
            Storage::disk($disk)->put($probe, 'ok');
            $read = Storage::disk($disk)->get($probe);
            Storage::disk($disk)->delete($probe);

            return ['status' => $read === 'ok' ? self::OK : self::DEGRADED, 'disk' => $disk, 'driver' => config("filesystems.disks.{$disk}.driver")];
        } catch (Throwable $e) {
            return ['status' => self::DOWN, 'disk' => $disk, 'error' => Str::limit($e->getMessage(), 200)];
        }
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return $this->channelHealth('email', ['mailer' => $mailer, 'transport' => config("mail.mailers.{$mailer}.transport")], ! in_array($mailer, ['', 'log', 'array'], true));
    }

    private function sms(): array
    {
        $providers = [];
        if (filled(config('services.etech.sms_login')) && filled(config('services.etech.sms_password'))) {
            $providers[] = 'etech';
        }
        if (filled(config('services.twilio.account_sid')) && filled(config('services.twilio.sms_from'))) {
            $providers[] = 'twilio';
        }

        return $this->channelHealth('sms', ['configured_providers' => $providers], $providers !== []);
    }

    /** Configuration presence + the real outcome of recent notification deliveries on that channel. */
    private function channelHealth(string $channel, array $meta, bool $configured): array
    {
        $recent = ['sent_24h' => 0, 'failed_24h' => 0];
        if (Schema::hasTable('notification_deliveries')) {
            $rows = DB::table('notification_deliveries')->whereIn('channel', [strtoupper($channel), $channel])->where('updated_at', '>=', now()->subDay())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            foreach ($rows as $status => $n) {
                if (in_array(strtoupper((string) $status), ['FAILED', 'DEAD_LETTERED', 'UNDELIVERABLE'], true)) {
                    $recent['failed_24h'] += (int) $n;
                } elseif (in_array(strtoupper((string) $status), ['SENT', 'DELIVERED'], true)) {
                    $recent['sent_24h'] += (int) $n;
                }
            }
        }
        $status = ! $configured ? self::NOT_CONFIGURED : ($recent['failed_24h'] > 0 && $recent['sent_24h'] === 0 ? self::DOWN : ($recent['failed_24h'] > 0 ? self::DEGRADED : self::OK));

        return ['status' => $status, ...$meta, ...$recent];
    }
}
