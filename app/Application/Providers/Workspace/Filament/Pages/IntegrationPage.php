<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/** Provider Portal screen "integration_settings" (Gap-Free spec ui_screen_register). */
final class IntegrationPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-terminal';

    protected static ?int $navigationSort = 18;

    protected static ?string $slug = 'integration';

    protected static string $permission = 'provider.settings.manage';

    protected static string $screen = 'integration_settings';

    protected function rows(): array
    {
        return [['provider_api' => url('/api/v1/provider-portal'), 'idempotency' => __('provider_workspace.ui.idempotency_rule'), 'source_system' => __('provider_workspace.ui.source_system_rule'), 'screens' => url('/api/v1/provider-portal/screens')]];
    }
}
