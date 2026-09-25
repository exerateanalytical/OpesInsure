<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * "Cases & tasks" screens (ICE ENG-6-001..006). Access = the same permission
 * strings as routes/cases.php; case confidentiality is filtered by the
 * WorkCase global scope (INV-6.5), so RESTRICTED / STR_RESTRICTED rows never
 * reach a user without cases.restricted.view / cases.str.view.
 */
trait CasesAccess
{
    protected static function casesPermission(): string
    {
        return 'cases.view';
    }

    protected static function casesWritePermission(): ?string
    {
        return null;
    }

    public static function casesAllows(?string $permission): bool
    {
        $user = auth()->user();
        if ($user === null || $permission === null) {
            return false;
        }

        return (bool) rescue(fn () => $user->hasPermission($permission), false, false);
    }

    public static function canViewAny(): bool
    {
        return static::casesAllows(static::casesPermission());
    }

    public static function canCreate(): bool
    {
        return static::casesAllows(static::casesWritePermission());
    }

    public static function canEdit($record): bool
    {
        return static::casesAllows(static::casesWritePermission());
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
        return 'Cases & tasks';
    }
}
