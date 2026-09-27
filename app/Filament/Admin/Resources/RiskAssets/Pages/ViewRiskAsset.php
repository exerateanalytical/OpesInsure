<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RiskAssets\Pages;

use App\Filament\Admin\Resources\RiskAssets\RiskAssetResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewRiskAsset extends RecordDetailPage
{
    protected static string $resource = RiskAssetResource::class;
}
