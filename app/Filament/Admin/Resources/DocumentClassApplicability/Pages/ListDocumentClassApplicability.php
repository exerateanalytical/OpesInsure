<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentClassApplicability\Pages;

use App\Filament\Admin\Resources\DocumentClassApplicability\DocumentClassApplicabilityResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentClassApplicability extends ListRecords
{
    protected static string $resource = DocumentClassApplicabilityResource::class;
}
