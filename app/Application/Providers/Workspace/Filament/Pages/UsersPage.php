<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "user_management" (Gap-Free spec ui_screen_register). */
final class UsersPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 17;

    protected static ?string $slug = 'users';

    protected static string $permission = 'provider.users.manage';

    protected static string $screen = 'user_management';

    protected function rows(): array
    {
        return array_map(fn ($r) => (array) $r, app(\App\Application\Providers\Workspace\ProviderAccess::class)->users($this->scope()));
    }
}
