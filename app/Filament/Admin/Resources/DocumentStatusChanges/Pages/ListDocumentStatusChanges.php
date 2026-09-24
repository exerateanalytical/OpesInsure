<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentStatusChanges\Pages;

use App\Filament\Admin\Resources\DocumentStatusChanges\DocumentStatusChangeResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentStatusChanges extends ListRecords
{
    protected static string $resource = DocumentStatusChangeResource::class;
}
