<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "disputes" (Gap-Free spec ui_screen_register). */
final class DisputesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'disputes';

    protected static string $permission = 'provider.dispute.view';

    protected static string $screen = 'disputes';

    protected function rows(): array
    {
        return $this->ops()->disputes($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
