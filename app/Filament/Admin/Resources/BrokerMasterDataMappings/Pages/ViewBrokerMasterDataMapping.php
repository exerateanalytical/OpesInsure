<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages;

use App\Filament\Admin\Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewBrokerMasterDataMapping extends RecordDetailPage
{
    protected static string $resource = BrokerMasterDataMappingResource::class;
}
