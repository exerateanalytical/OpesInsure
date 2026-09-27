<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataAliases\Pages;

use App\Filament\Admin\Resources\MasterDataAliases\MasterDataAliasResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataAlias extends RecordDetailPage
{
    protected static string $resource = MasterDataAliasResource::class;
}
