<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RegulatoryReports\Pages;

use App\Filament\Admin\Resources\RegulatoryReports\RegulatoryReportRunResource;
use App\Filament\Shared\Actions\ComplianceActions;
use Filament\Resources\Pages\ListRecords;

final class ListRegulatoryReports extends ListRecords
{
    protected static string $resource = RegulatoryReportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [ComplianceActions::reportPrepare()];
    }
}
