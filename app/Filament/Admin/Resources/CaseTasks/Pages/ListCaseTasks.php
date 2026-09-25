<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseTasks\Pages;

use App\Filament\Admin\Resources\CaseTasks\CaseTaskResource;
use Filament\Resources\Pages\ListRecords;

final class ListCaseTasks extends ListRecords
{
    protected static string $resource = CaseTaskResource::class;
}
