<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentIssuanceProfiles\Pages;

use App\Filament\Admin\Resources\DocumentIssuanceProfiles\DocumentIssuanceProfileResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDocumentIssuanceProfile extends RecordDetailPage
{
    protected static string $resource = DocumentIssuanceProfileResource::class;
}
