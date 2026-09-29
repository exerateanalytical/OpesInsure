<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InsuranceLines\Pages;

use App\Filament\Admin\Resources\InsuranceLines\InsuranceLineResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewInsuranceLine extends RecordDetailPage
{
    protected static string $resource = InsuranceLineResource::class;

    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), \App\Filament\Shared\Actions\CatalogueActions::createCoverage(), \App\Filament\Shared\Actions\CatalogueActions::createExclusion()];
    }
}
