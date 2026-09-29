<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * S12: every reported (unhandled) exception becomes one error_events row per fingerprint — class + file:line + scrubbed
 * message — with an occurrence count, first/last seen, the route PATTERN, a keyed hash of the user id and the release.
 * Never stored: request bodies, headers, query strings, IPs, SQL bindings, user ids, e-mails, phone numbers, numbers.
 * A RESOLVED fingerprint that happens again is reopened; IGNORED stays ignored but keeps counting. Never throws.
 */
final class ErrorEventRecorder
{
    private static bool $busy = false;

    public function capture(Throwable $e): void
    {
        if (self::$busy || ! (bool) config('monitoring.errors.enabled', true)) {
            return;
        }
        self::$busy = true;
        try {
            if (self::isDatabaseConnectionError($e)) {
                MonitoringSignals::record(MonitoringSignals::DB_CONNECTION_ERROR, (string) config('database.default'));
            }
            $this->store($e);
        } catch (Throwable) {
            // The database may be the thing that is broken; the log channel still has the exception.
        } finally {
            self::$busy = false;
        }
    }

    public static function isDatabaseConnectionError(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (! $x instanceof QueryException && ! $x instanceof PDOException) {
                continue;
            }
            $state = (string) ($x instanceof QueryException ? ($x->errorInfo[0] ?? $x->getCode()) : $x->getCode());
            if (str_starts_with($state, '08') || preg_match('/could not connect|connection refused|server closed the connection|no connection to the server|SQLSTATE\[08|Connection timed out|could not translate host/i', $x->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function scrub(string $message): string
    {
        $m = Str::before($message, ' (Connection:');
        $m = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '<email>', $m) ?? '';
        $m = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $m) ?? '';
        $m = preg_replace('/"[^"]*"|\'[^\']*\'/', '<str>', $m) ?? '';
        $m = preg_replace('/\+?\d[\d\s\-]{2,}\d|\d+/', '<n>', $m) ?? '';

        return mb_substr(trim($m), 0, 300);
    }

    private function store(Throwable $e): void
    {
        $file = Str::after(str_replace('\\', '/', $e->getFile()), str_replace('\\', '/', base_path()).'/');
        $message = self::scrub($e->getMessage());
        $fingerprint = hash('sha256', get_class($e).'|'.$file.'|'.$e->getLine().'|'.$message);
        [$route, $method] = $this->route();
        $userHash = $this->userHash();
        $release = ReleaseId::current();
        $now = now();

        DB::table('error_events')->upsert([[
            'id' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'exception_class' => mb_substr(get_class($e), 0, 255),
            'message' => $message, 'file' => mb_substr($file, 0, 255), 'line' => $e->getLine(), 'route' => $route, 'method' => $method,
            'user_id_hash' => $userHash, 'first_release_id' => $release, 'release_id' => $release, 'occurrences' => 1,
            'first_seen_at' => $now, 'last_seen_at' => $now, 'status' => 'OPEN', 'created_at' => $now, 'updated_at' => $now,
        ]], ['fingerprint'], [
            'occurrences' => DB::raw('error_events.occurrences + 1'),
            'last_seen_at' => DB::raw('excluded.last_seen_at'), 'updated_at' => DB::raw('excluded.updated_at'),
            'release_id' => DB::raw('excluded.release_id'), 'route' => DB::raw('excluded.route'), 'method' => DB::raw('excluded.method'),
            'user_id_hash' => DB::raw('excluded.user_id_hash'),
            'status' => DB::raw("CASE WHEN error_events.status = 'RESOLVED' THEN 'OPEN' ELSE error_events.status END"),
            'resolved_at' => DB::raw("CASE WHEN error_events.status = 'RESOLVED' THEN NULL ELSE error_events.resolved_at END"),
        ]);

        $ts = $now->getTimestamp();
        DB::table('error_event_counts')->upsert([['bucket_start' => $now->copy()->setTimestamp($ts - ($ts % 300)), 'total' => 1]],
            ['bucket_start'], ['total' => DB::raw('error_event_counts.total + 1')]);
    }

    /** @return array{0:?string, 1:?string} route pattern (never the concrete URL) and HTTP method */
    private function route(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['console:'.mb_substr((string) ($_SERVER['argv'][1] ?? ''), 0, 120), null];
        }
        $request = app()->bound('request') ? request() : null;
        $r = $request?->route();
        if ($r === null) {
            return [null, $request?->method()];
        }

        return [mb_substr((string) ($r->getName() ?: '/'.ltrim($r->uri(), '/')), 0, 255), $request->method()];
    }

    private function userHash(): ?string
    {
        $id = rescue(fn () => auth()->id() ?? (auth()->guard('api')->hasUser() ? auth()->guard('api')->id() : null), null, false);

        return $id === null ? null : substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 32);
    }
}
