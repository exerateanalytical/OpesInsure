<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaReportingCategories\Pages;

use App\Filament\Admin\Resources\CimaReportingCategories\CimaReportingCategoryResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaReportingCategories extends ListRecords
{
    protected static string $resource = CimaReportingCategoryResource::class;
}
