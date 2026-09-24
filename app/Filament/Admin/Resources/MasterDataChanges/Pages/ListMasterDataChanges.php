<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataChanges\Pages;

use App\Filament\Admin\Resources\MasterDataChanges\MasterDataChangeResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataChanges extends ListRecords
{
    protected static string $resource = MasterDataChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
