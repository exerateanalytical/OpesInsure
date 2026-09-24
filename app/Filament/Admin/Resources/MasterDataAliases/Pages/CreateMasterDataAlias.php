<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataAliases\Pages;

use App\Filament\Admin\Resources\MasterDataAliases\MasterDataAliasResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateMasterDataAlias extends CreateRecord
{
    protected static string $resource = MasterDataAliasResource::class;
}
