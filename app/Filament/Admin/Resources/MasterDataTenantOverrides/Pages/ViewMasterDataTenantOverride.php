<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataTenantOverrides\Pages;

use App\Filament\Admin\Resources\MasterDataTenantOverrides\MasterDataTenantOverrideResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataTenantOverride extends RecordDetailPage
{
    protected static string $resource = MasterDataTenantOverrideResource::class;
}
