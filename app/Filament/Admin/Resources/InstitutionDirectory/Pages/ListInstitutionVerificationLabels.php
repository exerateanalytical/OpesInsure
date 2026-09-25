<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory\Pages;

use App\Filament\Admin\Resources\InstitutionDirectory\InstitutionVerificationLabelResource;
use Filament\Resources\Pages\ListRecords;

final class ListInstitutionVerificationLabels extends ListRecords
{
    protected static string $resource = InstitutionVerificationLabelResource::class;
}
