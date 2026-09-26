<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "settlements" (Gap-Free spec ui_screen_register). */
final class SettlementsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'settlements';

    protected static string $permission = 'provider.settlement.view';

    protected static string $screen = 'settlements';

    protected function rows(): array
    {
        return $this->ws()->settlements($this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
