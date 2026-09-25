<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseTypes\Pages;

use App\Filament\Admin\Resources\CaseTypes\CaseTypeResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCaseType extends ViewRecord
{
    protected static string $resource = CaseTypeResource::class;
}
