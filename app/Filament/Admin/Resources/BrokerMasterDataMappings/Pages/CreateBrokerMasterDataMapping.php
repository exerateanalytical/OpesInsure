<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages;

use App\Filament\Admin\Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBrokerMasterDataMapping extends CreateRecord
{
    protected static string $resource = BrokerMasterDataMappingResource::class;
}
