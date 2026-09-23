<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MobileIssueReports\Pages;

use App\Filament\Admin\Resources\MobileIssueReports\MobileIssueReportResource;
use Filament\Resources\Pages\ListRecords;

final class ListMobileIssueReports extends ListRecords
{
    protected static string $resource = MobileIssueReportResource::class;
}
