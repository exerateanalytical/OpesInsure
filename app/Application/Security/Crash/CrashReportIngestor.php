<?php

declare(strict_types=1);

namespace App\Application\Security\Crash;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-MOB-007 crash-report ingestion without PII. Unauthenticated (a crash can
 * happen before login) and carries NO user, device, IP or session identifier.
 * Message and stack are scrubbed (emails, phone-like and long digit runs,
 * bearer/JWT tokens, UUIDs, URL query strings, home-directory paths), truncated,
 * and grouped by a fingerprint of (platform, error type, normalised top frames)
 * per app version, so repeat crashes increment a counter instead of adding rows.
 */
final class CrashReportIngestor
{
    /** @param array{platform: string, app_version: string, build?: ?string, os_version?: ?string, error_type: string, message?: ?string, stack?: ?string} $d */
    public function ingest(array $d): array
    {
        $message = $this->limit(self::scrub((string) ($d['message'] ?? '')), (int) config('security_centre.crash_reports.max_message_bytes', 500));
        $stack = $this->limit(self::scrub((string) ($d['stack'] ?? '')), (int) config('security_centre.crash_reports.max_stack_bytes', 8000));
        $errorType = mb_substr(self::scrub($d['error_type']), 0, 120);
        $frames = implode("\n", array_slice(array_filter(array_map('trim', explode("\n", preg_replace('/:\d+(:\d+)?/', '', $stack) ?? ''))), 0, 5));
        $fingerprint = hash('sha256', $d['platform'].'|'.$errorType.'|'.$frames);

        return DB::transaction(function () use ($d, $message, $stack, $errorType, $fingerprint) {
            $existing = DB::table('mobile_crash_reports')->where('fingerprint', $fingerprint)->where('app_version', $d['app_version'])->lockForUpdate()->first();
            if ($existing) {
                DB::table('mobile_crash_reports')->where('id', $existing->id)->update(['occurrences' => $existing->occurrences + 1, 'last_seen_at' => now()]);

                return ['id' => $existing->id, 'fingerprint' => $fingerprint, 'occurrences' => $existing->occurrences + 1];
            }
            $id = (string) Str::uuid();
            DB::table('mobile_crash_reports')->insert([
                'id' => $id, 'fingerprint' => $fingerprint, 'platform' => $d['platform'], 'app_version' => $d['app_version'], 'build' => $d['build'] ?? null,
                'os_version' => $d['os_version'] ?? null, 'error_type' => $errorType, 'message' => $message ?: null, 'stack' => $stack ?: null,
                'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);

            return ['id' => $id, 'fingerprint' => $fingerprint, 'occurrences' => 1];
        });
    }

    public static function scrub(string $s): string
    {
        $rules = [
            '/\b(?:bearer|token|authorization|password|passwd|secret|otp|pin)\b\s*[:=]?\s*\S+/i' => '[REDACTED_SECRET]',
            '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/' => '[REDACTED_JWT]',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[REDACTED_EMAIL]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[UUID]',
            '/\?[^\s"\')]*/' => '?[REDACTED_QUERY]',
            '#(/(?:Users|home|data/user/\d+)/)[^/\s]+#' => '$1[USER]',
            '/\+?\d[\d\s().-]{6,}\d/' => '[REDACTED_NUMBER]',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $s);
    }

    private function limit(string $s, int $bytes): string
    {
        return mb_strcut($s, 0, $bytes, 'UTF-8');
    }
}
