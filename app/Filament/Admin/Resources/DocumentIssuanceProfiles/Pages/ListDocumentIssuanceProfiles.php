<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentIssuanceProfiles\Pages;

use App\Filament\Admin\Resources\DocumentIssuanceProfiles\DocumentIssuanceProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentIssuanceProfiles extends ListRecords
{
    protected static string $resource = DocumentIssuanceProfileResource::class;
}
