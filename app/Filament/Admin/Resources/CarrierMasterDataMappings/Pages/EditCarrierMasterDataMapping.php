<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages;

use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use Filament\Resources\Pages\EditRecord;

final class EditCarrierMasterDataMapping extends EditRecord
{
    protected static string $resource = CarrierMasterDataMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
