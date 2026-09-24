<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataValues\Pages;

use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use Filament\Resources\Pages\EditRecord;

final class EditMasterDataValue extends EditRecord
{
    protected static string $resource = MasterDataValueResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
