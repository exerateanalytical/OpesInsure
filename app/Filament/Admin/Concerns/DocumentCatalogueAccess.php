<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * Document catalogue screens (types, packs, class applicability, requirement
 * matrix, product overrides) are for platform / compliance / product admins.
 * Seeded catalogue rows are read-only; the only mutations are deactivation
 * and maker-checker product overrides.
 */
trait DocumentCatalogueAccess
{
    public const DOC_CATALOGUE_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'PRODUCT_ADMIN'];

    public static function canAccessDocumentCatalogue(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::DOC_CATALOGUE_ROLES)->exists();
    }

    public static function canViewAny(): bool
    {
        return static::canAccessDocumentCatalogue();
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
        return 'Document catalogue';
    }
}
