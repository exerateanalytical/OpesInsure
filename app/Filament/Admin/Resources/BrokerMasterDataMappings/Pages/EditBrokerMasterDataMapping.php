<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages;

use App\Filament\Admin\Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource;
use Filament\Resources\Pages\EditRecord;

final class EditBrokerMasterDataMapping extends EditRecord
{
    protected static string $resource = BrokerMasterDataMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
