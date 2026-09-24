<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaTerms\Pages;

use App\Filament\Admin\Resources\CimaTerms\CimaTermResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCimaTerm extends ViewRecord
{
    protected static string $resource = CimaTermResource::class;
}
