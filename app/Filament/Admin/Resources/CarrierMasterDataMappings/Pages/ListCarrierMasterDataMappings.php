<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages;

use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use Filament\Resources\Pages\ListRecords;

final class ListCarrierMasterDataMappings extends ListRecords
{
    use \App\Filament\Shared\Concerns\OpensViewPage;

    protected static string $resource = CarrierMasterDataMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make(), \App\Filament\Shared\Actions\MasterDataOwnershipActions::mapForCarrier()];
    }
}
