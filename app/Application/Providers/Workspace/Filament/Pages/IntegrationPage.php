<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "integration_settings" (Gap-Free spec ui_screen_register). */
final class IntegrationPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 18;

    protected static ?string $slug = 'integration';

    protected static string $permission = 'provider.settings.manage';

    protected static string $screen = 'integration_settings';

    protected function rows(): array
    {
        return [['provider_api' => url('/api/v1/provider-portal'), 'idempotency' => 'Idempotency-Key required on POST', 'source_system' => 'tracked on provider claims', 'screens' => url('/api/v1/provider-portal/screens')]];
    }
}
