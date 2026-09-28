<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DataSubjectRequests\Pages;

use App\Filament\Admin\Resources\DataSubjectRequests\DataSubjectRequestResource;
use App\Filament\Shared\Actions\ComplianceActions;
use Filament\Resources\Pages\ListRecords;

final class ListDataSubjectRequests extends ListRecords
{
    protected static string $resource = DataSubjectRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [ComplianceActions::dsrReceive()];
    }
}
