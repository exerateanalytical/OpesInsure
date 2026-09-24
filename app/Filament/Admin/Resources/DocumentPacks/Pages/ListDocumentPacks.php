<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentPacks\Pages;

use App\Filament\Admin\Resources\DocumentPacks\DocumentPackResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentPacks extends ListRecords
{
    protected static string $resource = DocumentPackResource::class;
}
