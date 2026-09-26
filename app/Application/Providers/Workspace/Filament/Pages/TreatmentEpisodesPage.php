<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "treatment_episodes" (Gap-Free spec ui_screen_register). */
final class TreatmentEpisodesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'treatment-episodes';

    protected static string $permission = 'provider.treatment.view';

    protected static string $screen = 'treatment_episodes';

    protected function rows(): array
    {
        return $this->ops()->episodes($this->tenantId(), $this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
