<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * "Vehicle master data" screens: platform admins only. Rows are changed via
 * explicit actions that go through VehicleMasterAdminService /
 * VehicleMasterReviewService (history + admin_modified_at), never deleted.
 */
trait VehicleMasterAccess
{
    public const VEHICLE_MASTER_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'];

    public static function canManageVehicleMaster(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::VEHICLE_MASTER_ROLES)->exists();
    }

    public static function canViewAny(): bool
    {
        return static::canManageVehicleMaster();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
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
        return 'Vehicle master data';
    }
}
