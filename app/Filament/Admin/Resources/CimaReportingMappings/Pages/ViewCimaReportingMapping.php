<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaReportingMappings\Pages;

use App\Filament\Admin\Resources\CimaReportingMappings\CimaReportingMappingResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaReportingMapping extends RecordDetailPage
{
    protected static string $resource = CimaReportingMappingResource::class;
}
