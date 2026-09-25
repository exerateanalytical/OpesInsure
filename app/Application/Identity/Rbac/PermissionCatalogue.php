<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use App\Application\Identity\RoleCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-RBAC-001: the governed permission catalogue.
 *
 * Source of truth is config/permissions.php (documented, sensitive strings)
 * plus every permission a RoleCatalogue default grants. sync() mirrors it
 * into the `permissions` table so it can be listed/audited; grants still
 * live in roles.permissions (jsonb).
 *
 * Naming: module.action or module.resource.action (lower-case, '_' or '-'
 * allowed inside a segment, max 4 segments).
 */
final class PermissionCatalogue
{
    public const NAME_PATTERN = '/^[a-z0-9_-]+(\.[a-z0-9_-]+){1,3}$/';

    private const NON_CATEGORY_KEYS = ['never_grant_to', 'business_data'];

    public static function isValidName(string $permission): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $permission);
    }

    /** REQ-RBAC-004: does this permission unlock business data? */
    public static function isBusinessData(string $permission): bool
    {
        $cfg = (array) config('permissions.business_data', []);
        if (in_array($permission, (array) ($cfg['platform_exceptions'] ?? []), true)) {
            return false;
        }
        if (in_array($permission, (array) ($cfg['permissions'] ?? []), true)) {
            return true;
        }

        return in_array(Str::before($permission, '.'), (array) ($cfg['modules'] ?? []), true);
    }

    /** @return array<string, array{category:string, description:?string, suggested_roles:list<string>}> */
    public static function all(): array
    {
        $out = [];
        foreach ((array) config('permissions', []) as $category => $entries) {
            if (in_array($category, self::NON_CATEGORY_KEYS, true) || ! is_array($entries)) {
                continue;
            }
            foreach ($entries as $code => $meta) {
                if (is_string($code) && is_array($meta)) {
                    $out[$code] = ['category' => (string) $category, 'description' => $meta['description'] ?? null, 'suggested_roles' => array_values((array) ($meta['suggested_roles'] ?? []))];
                }
            }
        }
        foreach (RoleCatalogue::codes() as $role) {
            foreach (RoleCatalogue::defaultPermissions($role) as $code) {
                if ($code === '*') {
                    continue;
                }
                $out[$code] ??= ['category' => 'role_default', 'description' => null, 'suggested_roles' => []];
                if (! in_array($role, $out[$code]['suggested_roles'], true)) {
                    $out[$code]['suggested_roles'][] = $role;
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** Upsert the catalogue into `permissions`. Never deletes rows. Returns count. */
    public static function sync(): int
    {
        $now = now();
        $n = 0;
        foreach (self::all() as $code => $meta) {
            $parts = explode('.', $code);
            $values = [
                'module' => $parts[0],
                'resource' => count($parts) > 2 ? implode('.', array_slice($parts, 1, -1)) : null,
                'action' => (string) end($parts),
                'category' => $meta['category'],
                'is_business_data' => self::isBusinessData($code),
                'description' => $meta['description'],
                'suggested_roles' => json_encode($meta['suggested_roles']),
                'updated_at' => $now,
            ];
            $exists = DB::table('permissions')->where('code', $code)->exists();
            $exists
                ? DB::table('permissions')->where('code', $code)->update($values)
                : DB::table('permissions')->insert([...$values, 'id' => (string) Str::uuid(), 'code' => $code, 'created_at' => $now]);
            $n++;
        }

        return $n;
    }
}
