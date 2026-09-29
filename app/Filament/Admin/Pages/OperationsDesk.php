<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\MiscOperationsActions;
use App\Filament\Shared\Pages\ActionDesk;

/** Fulfilment, provider networks, health / fraud, legal and policy records, distribution and support (MiscOperationsActions). */
final class OperationsDesk extends ActionDesk
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-wrench';

    protected static ?string $slug = 'operations-desk';

    protected static ?int $navigationSort = 95;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static function deskPermissions(): array
    {
        return MiscOperationsActions::permissions();
    }

    protected static function deskKey(): string
    {
        return 'operations';
    }

    protected function getHeaderActions(): array
    {
        return MiscOperationsActions::groups();
    }
}
