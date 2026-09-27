<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataImports\Pages;

use App\Filament\Admin\Resources\MasterDataImports\MasterDataImportResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataImport extends RecordDetailPage
{
    protected static string $resource = MasterDataImportResource::class;
}
