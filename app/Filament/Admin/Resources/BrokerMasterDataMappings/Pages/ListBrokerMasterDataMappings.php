<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages;

use App\Filament\Admin\Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource;
use Filament\Resources\Pages\ListRecords;

final class ListBrokerMasterDataMappings extends ListRecords
{
    protected static string $resource = BrokerMasterDataMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
