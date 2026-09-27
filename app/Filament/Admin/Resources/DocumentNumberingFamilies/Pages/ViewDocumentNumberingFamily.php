<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentNumberingFamilies\Pages;

use App\Filament\Admin\Resources\DocumentNumberingFamilies\DocumentNumberingFamilyResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDocumentNumberingFamily extends RecordDetailPage
{
    protected static string $resource = DocumentNumberingFamilyResource::class;
}
