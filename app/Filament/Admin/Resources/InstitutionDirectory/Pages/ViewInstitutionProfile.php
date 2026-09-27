<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory\Pages;

use App\Filament\Admin\Resources\InstitutionDirectory\InstitutionProfileResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewInstitutionProfile extends RecordDetailPage
{
    protected static string $resource = InstitutionProfileResource::class;
}
