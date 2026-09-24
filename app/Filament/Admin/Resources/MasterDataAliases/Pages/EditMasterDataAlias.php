<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataAliases\Pages;

use App\Filament\Admin\Resources\MasterDataAliases\MasterDataAliasResource;
use Filament\Resources\Pages\EditRecord;

final class EditMasterDataAlias extends EditRecord
{
    protected static string $resource = MasterDataAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
