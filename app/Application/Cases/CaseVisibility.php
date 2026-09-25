<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-CAS-001 / ICE INV-6.5: confidentiality filter applied at the query layer
 * (global scope on WorkCase), not only in the UI.
 *
 *  NORMAL          visible to anyone who can reach the case API/screen
 *  RESTRICTED      needs cases.restricted.view
 *  STR_RESTRICTED  needs cases.str.view (tipping-off protection)
 *
 * With no authenticated user (scheduler, queue workers, migrations) the scope
 * is not applied: system processes see every case.
 */
final class CaseVisibility
{
    public const LEVELS = ['NORMAL', 'RESTRICTED', 'STR_RESTRICTED'];

    public const PERMISSION = ['RESTRICTED' => 'cases.restricted.view', 'STR_RESTRICTED' => 'cases.str.view'];

    /** @return list<string> */
    public static function levelsFor(mixed $user): array
    {
        $levels = ['NORMAL'];
        foreach (self::PERMISSION as $level => $permission) {
            if (self::allows($user, $permission)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    public static function apply(Builder $q): void
    {
        $user = rescue(fn () => auth()->user(), null, false);
        if ($user === null) {
            return;
        }
        $q->whereIn($q->qualifyColumn('confidentiality'), self::levelsFor($user));
    }

    private static function allows(mixed $user, string $permission): bool
    {
        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }
        if (! app()->bound(TenantContext::class) || rescue(fn () => app(TenantContext::class)->id(), null, false) === null) {
            // Without a tenant only platform SYSTEM_ADMIN bypass can apply.
            return method_exists($user, 'memberships') && $user->memberships()->where('status', 'ACTIVE')->where('role_code', 'SYSTEM_ADMIN')->exists();
        }

        return (bool) $user->hasPermission($permission);
    }
}
