<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataMergeRequests\Pages;

use App\Filament\Admin\Resources\MasterDataMergeRequests\MasterDataMergeRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataMergeRequests extends ListRecords
{
    protected static string $resource = MasterDataMergeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
