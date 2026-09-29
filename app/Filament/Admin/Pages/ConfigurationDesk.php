<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\MiscConfigActions;
use App\Filament\Shared\Pages\ActionDesk;

/** Catalogues, rules, templates, queues / SLA, governed configuration and organisation settings (MiscConfigActions). */
final class ConfigurationDesk extends ActionDesk
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    protected static ?string $slug = 'configuration-desk';

    protected static ?int $navigationSort = 95;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static function deskPermissions(): array
    {
        return MiscConfigActions::permissions();
    }

    protected static function deskKey(): string
    {
        return 'configuration';
    }

    protected function getHeaderActions(): array
    {
        return MiscConfigActions::groups();
    }
}
