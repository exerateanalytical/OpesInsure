<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentNumberingFamilies\Pages;

use App\Filament\Admin\Resources\DocumentNumberingFamilies\DocumentNumberingFamilyResource;
use Filament\Resources\Pages\EditRecord;

final class EditDocumentNumberingFamily extends EditRecord
{
    protected static string $resource = DocumentNumberingFamilyResource::class;
}
