<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament;

use App\Application\Providers\Portal\ProviderScope;
use App\Models\TenantMembership;
use App\Models\User;

/** Who may enter the provider panel, and in which tenant (insurer workspace) it opens. */
final class ProviderPanelAccess
{
    public static function membership(?User $user): ?TenantMembership
    {
        if (! $user || $user->status !== 'ACTIVE' || ProviderScope::providerIdsFor($user) === []) {
            return null;
        }

        return TenantMembership::query()->where('user_id', $user->getKey())->where('status', 'ACTIVE')
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))->orderBy('created_at')->first();
    }

    public static function allows(?User $user): bool
    {
        return self::membership($user) !== null;
    }
}
