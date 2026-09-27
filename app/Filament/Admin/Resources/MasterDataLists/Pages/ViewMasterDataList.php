<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataLists\Pages;

use App\Filament\Admin\Resources\MasterDataLists\MasterDataListResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataList extends RecordDetailPage
{
    protected static string $resource = MasterDataListResource::class;
}
