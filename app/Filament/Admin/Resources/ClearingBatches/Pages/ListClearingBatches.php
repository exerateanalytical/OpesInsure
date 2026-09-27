<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ClearingBatches\Pages;

use App\Filament\Admin\Resources\ClearingBatches\ClearingBatchResource;
use App\Filament\Shared\Actions\ClearingActions;
use Filament\Resources\Pages\ListRecords;

final class ListClearingBatches extends ListRecords
{
    protected static string $resource = ClearingBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [ClearingActions::open()];
    }
}
