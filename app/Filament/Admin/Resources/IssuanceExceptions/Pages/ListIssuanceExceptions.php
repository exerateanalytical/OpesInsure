<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IssuanceExceptions\Pages;

use App\Filament\Admin\Resources\IssuanceExceptions\IssuanceExceptionResource;
use App\Filament\Shared\Actions\IssuanceActions;
use Filament\Resources\Pages\ListRecords;

final class ListIssuanceExceptions extends ListRecords
{
    protected static string $resource = IssuanceExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [IssuanceActions::exceptionScan()];
    }
}
