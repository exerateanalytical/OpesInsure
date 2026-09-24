<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataDomains\Pages;

use App\Filament\Admin\Resources\MasterDataDomains\MasterDataDomainResource;
use Filament\Resources\Pages\EditRecord;

final class EditMasterDataDomain extends EditRecord
{
    protected static string $resource = MasterDataDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
