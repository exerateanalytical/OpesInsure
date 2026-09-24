<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaReportingMappings\Pages;

use App\Filament\Admin\Resources\CimaReportingMappings\CimaReportingMappingResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaReportingMappings extends ListRecords
{
    protected static string $resource = CimaReportingMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CimaReportingMappingResource::createAction()];
    }
}
