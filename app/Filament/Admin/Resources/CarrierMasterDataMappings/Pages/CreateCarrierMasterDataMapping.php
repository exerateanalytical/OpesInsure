<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages;

use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCarrierMasterDataMapping extends CreateRecord
{
    protected static string $resource = CarrierMasterDataMappingResource::class;
}
