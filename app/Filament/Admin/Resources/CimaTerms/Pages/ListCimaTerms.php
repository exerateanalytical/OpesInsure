<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaTerms\Pages;

use App\Filament\Admin\Resources\CimaTerms\CimaTermResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaTerms extends ListRecords
{
    protected static string $resource = CimaTermResource::class;
}
