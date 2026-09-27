<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaLegalReferences\Pages;

use App\Filament\Admin\Resources\CimaLegalReferences\CimaLegalReferenceResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaLegalReference extends RecordDetailPage
{
    protected static string $resource = CimaLegalReferenceResource::class;
}
