<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * S12: tiny counters for failures that leave no database row (payment provider authentication failures, database
 * connection errors — the latter cannot be written to the database by definition). Kept in the cache per 5-minute
 * bucket for two hours; read back by AlertChecks. Never throws.
 */
final class MonitoringSignals
{
    public const PAYMENT_AUTH_FAILURE = 'payment_auth_failure';

    public const DB_CONNECTION_ERROR = 'db_connection_error';

    private const TTL = 7200;

    public static function record(string $signal, string $source = 'default'): void
    {
        try {
            $bucket = self::bucket(now()->getTimestamp());
            $key = "monitoring:signal:{$signal}:{$bucket}";
            Cache::add($key, 0, self::TTL);
            Cache::increment($key);
            $sourcesKey = "monitoring:signal-sources:{$signal}:{$bucket}";
            $sources = (array) Cache::get($sourcesKey, []);
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            Cache::put($sourcesKey, $sources, self::TTL);
        } catch (Throwable) {
            // Monitoring must never break the caller.
        }
    }

    /** @return array{total:int, sources:array<string,int>} over the last $minutes */
    public static function recent(string $signal, int $minutes): array
    {
        $total = 0;
        $sources = [];
        try {
            $now = now()->getTimestamp();
            $from = self::bucket($now - $minutes * 60);
            for ($t = self::bucket($now); $t >= $from; $t -= 300) {
                $total += (int) Cache::get("monitoring:signal:{$signal}:{$t}", 0);
                foreach ((array) Cache::get("monitoring:signal-sources:{$signal}:{$t}", []) as $s => $n) {
                    $sources[$s] = ($sources[$s] ?? 0) + (int) $n;
                }
            }
        } catch (Throwable) {
        }

        return ['total' => $total, 'sources' => $sources];
    }

    private static function bucket(int $ts): int
    {
        return $ts - ($ts % 300);
    }
}
