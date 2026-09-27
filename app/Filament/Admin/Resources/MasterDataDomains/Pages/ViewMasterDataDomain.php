<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataDomains\Pages;

use App\Filament\Admin\Resources\MasterDataDomains\MasterDataDomainResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataDomain extends RecordDetailPage
{
    protected static string $resource = MasterDataDomainResource::class;
}
