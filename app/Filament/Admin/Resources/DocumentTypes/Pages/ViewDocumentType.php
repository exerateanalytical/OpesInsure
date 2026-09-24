<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTypes\Pages;

use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewDocumentType extends ViewRecord
{
    protected static string $resource = DocumentTypeResource::class;
}
