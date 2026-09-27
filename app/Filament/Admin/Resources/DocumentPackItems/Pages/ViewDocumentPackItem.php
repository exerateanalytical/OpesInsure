<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentPackItems\Pages;

use App\Filament\Admin\Resources\DocumentPackItems\DocumentPackItemResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDocumentPackItem extends RecordDetailPage
{
    protected static string $resource = DocumentPackItemResource::class;
}
