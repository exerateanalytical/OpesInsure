<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;
}
