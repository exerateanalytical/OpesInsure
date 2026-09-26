<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "provider_accounts" (Gap-Free spec ui_screen_register). */
final class AccountsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'accounts';

    protected static string $permission = 'provider.finance.view';

    protected static string $screen = 'provider_accounts';

    protected function cards(): array
    {
        return $this->ws()->aging($this->user(), $this->scope(), []);
    }

    protected function rows(): array
    {
        return $this->ws()->accounts($this->user(), $this->scope());
    }
}
