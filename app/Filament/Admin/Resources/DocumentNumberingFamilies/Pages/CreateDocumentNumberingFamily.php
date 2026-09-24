<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentNumberingFamilies\Pages;

use App\Filament\Admin\Resources\DocumentNumberingFamilies\DocumentNumberingFamilyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDocumentNumberingFamily extends CreateRecord
{
    protected static string $resource = DocumentNumberingFamilyResource::class;
}
