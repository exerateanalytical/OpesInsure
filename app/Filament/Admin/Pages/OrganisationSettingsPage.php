<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Pages\OrganizationSettings;

/** Admin panel: platform / system admins pick any organisation; everyone else manages only their own preferences. */
final class OrganisationSettingsPage extends OrganizationSettings
{
    public const MANAGER_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'];

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 91;

    public static function canAccess(): bool
    {
        return auth()->user() !== null;
    }

    protected function isManager(): bool
    {
        return (bool) auth()->user()?->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::MANAGER_ROLES)->exists();
    }

    protected function canManageTenant(): bool
    {
        return $this->isManager();
    }

    protected function tenantSelectable(): bool
    {
        return $this->isManager();
    }

    protected function resolveTenantId(): ?string
    {
        return $this->isManager() ? auth()->user()?->memberships()->where('status', 'ACTIVE')->orderBy('created_at')->value('tenant_id') : null;
    }
}
