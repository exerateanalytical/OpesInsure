<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentRequirements\Pages;

use App\Filament\Admin\Resources\DocumentRequirements\DocumentRequirementResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDocumentRequirement extends RecordDetailPage
{
    protected static string $resource = DocumentRequirementResource::class;
}
