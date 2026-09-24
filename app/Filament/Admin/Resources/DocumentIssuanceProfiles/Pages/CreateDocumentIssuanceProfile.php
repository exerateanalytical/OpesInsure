<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentIssuanceProfiles\Pages;

use App\Filament\Admin\Resources\DocumentIssuanceProfiles\DocumentIssuanceProfileResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDocumentIssuanceProfile extends CreateRecord
{
    protected static string $resource = DocumentIssuanceProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['opes_rendering_authorized'])) {
            $data['authorized_by'] ??= auth()->id();
            $data['authorized_at'] ??= now();
        }

        return $data;
    }
}
