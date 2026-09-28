<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ComplianceCases\Pages;

use App\Filament\Admin\Resources\ComplianceCases\ComplianceCaseResource;
use App\Filament\Shared\Actions\ComplianceActions;
use Filament\Resources\Pages\ListRecords;

final class ListComplianceCases extends ListRecords
{
    protected static string $resource = ComplianceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [ComplianceActions::caseOpen(), ComplianceActions::fraudAlert()];
    }
}
