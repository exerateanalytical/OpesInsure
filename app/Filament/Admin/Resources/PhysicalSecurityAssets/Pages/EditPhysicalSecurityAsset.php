<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages;

use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use Filament\Resources\Pages\EditRecord;

final class EditPhysicalSecurityAsset extends EditRecord
{
    protected static string $resource = PhysicalSecurityAssetResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PhysicalSecurityAssetResource::prepare($data, $this->record);
    }
}
