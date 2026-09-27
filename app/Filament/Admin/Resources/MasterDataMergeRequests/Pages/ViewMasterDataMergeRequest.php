<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataMergeRequests\Pages;

use App\Filament\Admin\Resources\MasterDataMergeRequests\MasterDataMergeRequestResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataMergeRequest extends RecordDetailPage
{
    protected static string $resource = MasterDataMergeRequestResource::class;
}
