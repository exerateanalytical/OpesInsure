<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataValues\Pages;

use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataValues extends ListRecords
{
    protected static string $resource = MasterDataValueResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
