<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataTenantOverrides\Pages;

use App\Filament\Admin\Resources\MasterDataTenantOverrides\MasterDataTenantOverrideResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataTenantOverrides extends ListRecords
{
    protected static string $resource = MasterDataTenantOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
