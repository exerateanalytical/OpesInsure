<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * CIMA Regulatory Dictionary screens are for platform/compliance admins only.
 * Regulatory rows are read-only in the panel: changes go through explicit
 * actions (new effective-dated version, maker-checker proposals).
 */
trait CimaRegulatoryAccess
{
    public const CIMA_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'];

    public static function canAccessCima(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::CIMA_ROLES)->exists();
    }

    public static function canViewAny(): bool
    {
        return static::canAccessCima();
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
        return 'CIMA Regulatory Dictionary';
    }
}
