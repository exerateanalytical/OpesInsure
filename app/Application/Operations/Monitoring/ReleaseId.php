<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

/**
 * S12: the running release. APP_RELEASE_ID (config monitoring.release) wins; else deploy.sh's release directory
 * (/srv/opesinsure/releases/rYYYYMMDD-HHMMSS, which base_path() resolves to through the `current` symlink); else a
 * RELEASE file at the project root; else "dev".
 */
final class ReleaseId
{
    private static ?string $memo = null;

    public static function current(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $id = trim((string) config('monitoring.release'));
        if ($id === '') {
            $dir = basename((string) (realpath(base_path()) ?: base_path()));
            if (preg_match('/^r\d{8}-\d{6}$/', $dir) === 1) {
                $id = $dir;
            } elseif (is_file(base_path('RELEASE'))) {
                $id = trim((string) @file_get_contents(base_path('RELEASE')));
            }
        }
        $id = mb_substr(preg_replace('/[^A-Za-z0-9._\-]/', '', $id) ?? '', 0, 64);

        return self::$memo = ($id !== '' ? $id : 'dev');
    }

    public static function flush(): void
    {
        self::$memo = null;
    }
}
