<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataLists\Pages;

use App\Filament\Admin\Resources\MasterDataLists\MasterDataListResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataLists extends ListRecords
{
    protected static string $resource = MasterDataListResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
