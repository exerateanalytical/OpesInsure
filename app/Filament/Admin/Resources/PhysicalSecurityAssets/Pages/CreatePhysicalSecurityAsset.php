<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages;

use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePhysicalSecurityAsset extends CreateRecord
{
    protected static string $resource = PhysicalSecurityAssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['recorded_by' => auth()->id()] + PhysicalSecurityAssetResource::prepare($data);
    }
}
