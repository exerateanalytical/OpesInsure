<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExclusionDefinitions\Pages;

use App\Filament\Admin\Resources\ExclusionDefinitions\ExclusionDefinitionResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewExclusionDefinition extends RecordDetailPage
{
    protected static string $resource = ExclusionDefinitionResource::class;
}
