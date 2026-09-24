<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataImports\Pages;

use App\Filament\Admin\Resources\MasterDataImports\MasterDataImportResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataImports extends ListRecords
{
    protected static string $resource = MasterDataImportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
