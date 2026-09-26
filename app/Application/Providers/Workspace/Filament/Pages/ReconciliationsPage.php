<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "reconciliation" (Gap-Free spec ui_screen_register). */
final class ReconciliationsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'reconciliations';

    protected static string $permission = 'provider.reconciliation.view';

    protected static string $screen = 'reconciliation';

    protected function rows(): array
    {
        return $this->ops()->reconciliations($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
