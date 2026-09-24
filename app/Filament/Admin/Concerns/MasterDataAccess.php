<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * "Master data" (MDM-001…020) screens: platform/compliance admins only.
 * Values are editable without a deploy; nothing is ever deleted — values are
 * deactivated (seeded rows are also protected by a DB trigger).
 */
trait MasterDataAccess
{
    public const MASTER_DATA_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'];

    public static function canManageMasterData(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::MASTER_DATA_ROLES)->exists();
    }

    public static function canViewAny(): bool
    {
        return static::canManageMasterData();
    }

    public static function canCreate(): bool
    {
        return static::canManageMasterData();
    }

    public static function canEdit($record): bool
    {
        return static::canManageMasterData();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Master data';
    }
}
