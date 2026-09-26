<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "contracts" (Gap-Free spec ui_screen_register). */
final class ContractsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'contracts';

    protected static string $permission = 'provider_portal.network.view';

    protected static string $screen = 'contracts';

    protected function rows(): array
    {
        return array_map(fn ($r) => (array) $r, app(\App\Application\Providers\Portal\ProviderPortalService::class)->contracts($this->tenantId(), $this->scope()));
    }
}
