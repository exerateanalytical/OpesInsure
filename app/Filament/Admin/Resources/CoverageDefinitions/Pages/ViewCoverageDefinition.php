<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CoverageDefinitions\Pages;

use App\Filament\Admin\Resources\CoverageDefinitions\CoverageDefinitionResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCoverageDefinition extends RecordDetailPage
{
    protected static string $resource = CoverageDefinitionResource::class;
}
