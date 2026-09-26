<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "claims_list" (Gap-Free spec ui_screen_register). */
final class ClaimsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'claims';

    protected static string $permission = 'provider.claim.view';

    protected static string $screen = 'claims_list';

    protected function rows(): array
    {
        return $this->ws()->claims($this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
