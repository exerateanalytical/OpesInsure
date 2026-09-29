<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\MiscPlatformActions;
use App\Filament\Shared\Actions\MiscSupport;
use App\Filament\Shared\Pages\ActionDesk;

/** Platform administration (default timezone, setup completion, platform identity, new organisations): platform tenant only. */
final class PlatformDesk extends ActionDesk
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-server-cog';

    protected static ?string $slug = 'platform-desk';

    protected static ?int $navigationSort = 96;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function canAccess(): bool
    {
        return parent::canAccess() && MiscSupport::isPlatformTenant();
    }

    protected static function deskPermissions(): array
    {
        return MiscPlatformActions::permissions();
    }

    protected static function deskKey(): string
    {
        return 'platform';
    }

    protected function getHeaderActions(): array
    {
        return MiscPlatformActions::all();
    }
}
