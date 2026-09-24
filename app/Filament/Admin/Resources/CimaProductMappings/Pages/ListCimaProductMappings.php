<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaProductMappings\Pages;

use App\Filament\Admin\Resources\CimaProductMappings\CimaProductMappingResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaProductMappings extends ListRecords
{
    protected static string $resource = CimaProductMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CimaProductMappingResource::createAction()];
    }
}
