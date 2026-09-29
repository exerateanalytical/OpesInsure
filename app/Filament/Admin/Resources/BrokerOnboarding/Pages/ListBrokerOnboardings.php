<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerOnboarding\Pages;

use App\Filament\Admin\Resources\BrokerOnboarding\BrokerOnboardingResource;
use Filament\Resources\Pages\ListRecords;

final class ListBrokerOnboardings extends ListRecords
{
    protected static string $resource = BrokerOnboardingResource::class;

    public function getSubheading(): ?string
    {
        return __('bulk_onboarding.intro');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
