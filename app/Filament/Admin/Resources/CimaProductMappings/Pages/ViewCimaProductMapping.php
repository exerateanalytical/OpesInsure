<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaProductMappings\Pages;

use App\Filament\Admin\Resources\CimaProductMappings\CimaProductMappingResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaProductMapping extends RecordDetailPage
{
    protected static string $resource = CimaProductMappingResource::class;
}
