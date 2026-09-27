<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataValues\Pages;

use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataValue extends RecordDetailPage
{
    protected static string $resource = MasterDataValueResource::class;
}
