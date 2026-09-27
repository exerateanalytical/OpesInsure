<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages;

use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCarrierMasterDataMapping extends RecordDetailPage
{
    protected static string $resource = CarrierMasterDataMappingResource::class;
}
