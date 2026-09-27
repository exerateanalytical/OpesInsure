<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages;

use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewPhysicalSecurityAsset extends RecordDetailPage
{
    protected static string $resource = PhysicalSecurityAssetResource::class;
}
