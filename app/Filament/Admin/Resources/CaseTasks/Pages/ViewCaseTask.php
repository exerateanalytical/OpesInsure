<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseTasks\Pages;

use App\Filament\Admin\Resources\CaseTasks\CaseTaskResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCaseTask extends ViewRecord
{
    protected static string $resource = CaseTaskResource::class;
}
