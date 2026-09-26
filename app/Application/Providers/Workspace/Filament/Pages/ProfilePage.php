<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "provider_profile" (Gap-Free spec ui_screen_register). */
final class ProfilePage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'profile';

    protected static string $permission = 'provider_portal.profile.view';

    protected static string $screen = 'provider_profile';

    protected function rows(): array
    {
        $p = (array) app(\App\Application\Providers\ProviderRegistry::class)->find($this->scope()->providerId);

        return [array_intersect_key($p, array_flip(['name', 'official_name', 'category', 'provider_type_code', 'credentialing_status', 'ownership_type', 'region_code', 'health_district', 'license_or_authorization_reference', 'data_status']))];
    }
}
