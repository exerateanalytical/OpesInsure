<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "admissions_list" (Gap-Free spec ui_screen_register). */
final class AdmissionsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'admissions';

    protected static string $permission = 'provider.preauth.view';

    protected static string $screen = 'admissions_list';

    protected function rows(): array
    {
        return $this->ws()->preauthorizations($this->tenantId(), $this->user(), $this->scope(), ['request_type' => 'ADMISSION', 'per_page' => 100])['data'];
    }
}
