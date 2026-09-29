<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TariffVersions\Pages;

use App\Filament\Admin\Resources\TariffVersions\TariffVersionResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewTariffVersion extends RecordDetailPage
{
    protected static string $resource = TariffVersionResource::class;

    /** UI batch 24: tariff lifecycle (submit / approve / reject / schedule / activate / expire) via TariffGovernanceService. */
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\TariffActions::group(), ...parent::getHeaderActions()];
    }
}
