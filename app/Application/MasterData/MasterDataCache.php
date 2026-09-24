<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-domain catalog_version drives both server caches and the app's offline
 * cache (GET /api/v1/master-data/versions). Any change bumps the version.
 */
final class MasterDataCache
{
    private const VERSIONS_KEY = 'master-data:versions';

    private static bool $muted = false;

    /** Suspend per-row bumps (bulk seeding bumps once per domain itself). */
    public static function muted(callable $fn): mixed
    {
        $was = self::$muted;
        self::$muted = true;
        try {
            return $fn();
        } finally {
            self::$muted = $was;
        }
    }

    public static function touch(?string $domainCode): void
    {
        if (self::$muted || ! $domainCode) {
            return;
        }
        self::bump($domainCode);
    }

    public static function bump(string $domainCode): void
    {
        try {
            DB::table('master_data_domains')->where('code', $domainCode)
                ->update(['catalog_version' => DB::raw('catalog_version + 1'), 'updated_at' => now()]);
        } catch (Throwable) {
            // table missing during early migrations: nothing cached yet
        }
        Cache::forget(self::VERSIONS_KEY);
    }

    /** @return array<string, array{version:int, updated_at:?string}> */
    public static function versions(): array
    {
        return Cache::rememberForever(self::VERSIONS_KEY, fn () => DB::table('master_data_domains')->where('status', 'ACTIVE')
            ->orderBy('sort_order')->orderBy('code')->get(['code', 'catalog_version', 'updated_at'])
            ->mapWithKeys(fn ($d) => [$d->code => ['version' => (int) $d->catalog_version, 'updated_at' => $d->updated_at ? (string) $d->updated_at : null]])
            ->all());
    }

    public static function version(string $domain): int
    {
        return self::versions()[$domain]['version'] ?? 0;
    }

    public static function remember(string $domain, string $key, callable $fn): mixed
    {
        $v = $domain === '_index' ? md5((string) json_encode(self::versions())) : (string) self::version($domain);

        return Cache::rememberForever("master-data:{$domain}:v{$v}:{$key}", $fn);
    }

    public static function flushAll(): void
    {
        Cache::forget(self::VERSIONS_KEY);
    }
}
