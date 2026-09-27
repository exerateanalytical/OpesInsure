<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataChanges\Pages;

use App\Filament\Admin\Resources\MasterDataChanges\MasterDataChangeResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataChange extends RecordDetailPage
{
    protected static string $resource = MasterDataChangeResource::class;
}
