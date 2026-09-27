<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaReportingCategories\Pages;

use App\Filament\Admin\Resources\CimaReportingCategories\CimaReportingCategoryResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaReportingCategory extends RecordDetailPage
{
    protected static string $resource = CimaReportingCategoryResource::class;
}
