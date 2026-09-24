<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/** Document engine admin screens (DOC-ADM-001…020): platform / compliance admins. */
trait DocumentEngineAccess
{
    public static function canAccessDocumentEngine(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
    }

    public static function canViewAny(): bool
    {
        return static::canAccessDocumentEngine();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::canAccessDocumentEngine();
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
        return 'Document engine';
    }
}
