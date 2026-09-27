<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductDocumentRequirements\Pages;

use App\Filament\Admin\Resources\ProductDocumentRequirements\ProductDocumentRequirementResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewProductDocumentRequirement extends RecordDetailPage
{
    protected static string $resource = ProductDocumentRequirementResource::class;
}
