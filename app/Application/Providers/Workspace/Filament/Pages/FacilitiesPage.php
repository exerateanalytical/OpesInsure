<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "facility_management" (Gap-Free spec ui_screen_register). */
final class FacilitiesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'facilities';

    protected static string $permission = 'provider_portal.profile.view';

    protected static string $screen = 'facility_management';

    protected function rows(): array
    {
        $ids = app(\App\Application\Providers\Workspace\ProviderAccess::class)->facilityIds($this->user(), $this->scope());

        return \Illuminate\Support\Facades\DB::table('provider_facilities')->whereIn('id', $ids)->orderBy('code')->get(['code', 'name', 'facility_type_code', 'city_code', 'status'])->map(fn ($f) => (array) $f)->all();
    }
}
