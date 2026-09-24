<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTypes\Pages;

use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentTypes extends ListRecords
{
    protected static string $resource = DocumentTypeResource::class;
}
