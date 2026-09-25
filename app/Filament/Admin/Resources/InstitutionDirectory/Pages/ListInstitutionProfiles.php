<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory\Pages;

use App\Filament\Admin\Resources\InstitutionDirectory\InstitutionProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListInstitutionProfiles extends ListRecords
{
    protected static string $resource = InstitutionProfileResource::class;
}
