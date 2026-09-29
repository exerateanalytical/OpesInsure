<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SecurityFindings\Pages;

use App\Filament\Admin\Resources\SecurityFindings\SecurityFindingResource;
use App\Filament\Shared\Actions\AccountSecurityActions;
use Filament\Resources\Pages\ListRecords;

final class ListSecurityFindings extends ListRecords
{
    protected static string $resource = SecurityFindingResource::class;

    protected function getHeaderActions(): array
    {
        return [AccountSecurityActions::findingReport()];
    }
}
