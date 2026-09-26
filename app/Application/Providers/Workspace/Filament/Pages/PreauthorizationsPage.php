<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "preauthorization_list" (Gap-Free spec ui_screen_register). */
final class PreauthorizationsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'preauthorizations';

    protected static string $permission = 'provider.preauth.view';

    protected static string $screen = 'preauthorization_list';

    protected function rows(): array
    {
        return $this->ws()->preauthorizations($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
