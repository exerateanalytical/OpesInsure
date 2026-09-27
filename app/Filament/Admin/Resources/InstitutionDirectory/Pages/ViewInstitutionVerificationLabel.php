<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory\Pages;

use App\Filament\Admin\Resources\InstitutionDirectory\InstitutionVerificationLabelResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewInstitutionVerificationLabel extends RecordDetailPage
{
    protected static string $resource = InstitutionVerificationLabelResource::class;
}
