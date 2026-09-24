<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductDocumentRequirements\Pages;

use App\Filament\Admin\Resources\ProductDocumentRequirements\ProductDocumentRequirementResource;
use Filament\Resources\Pages\ListRecords;

final class ListProductDocumentRequirements extends ListRecords
{
    protected static string $resource = ProductDocumentRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [ProductDocumentRequirementResource::createAction()];
    }
}
