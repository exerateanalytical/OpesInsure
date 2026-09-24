<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentPackItems\Pages;

use App\Filament\Admin\Resources\DocumentPackItems\DocumentPackItemResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentPackItems extends ListRecords
{
    protected static string $resource = DocumentPackItemResource::class;
}
