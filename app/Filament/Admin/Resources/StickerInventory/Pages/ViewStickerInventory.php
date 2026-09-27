<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StickerInventory\Pages;

use App\Filament\Admin\Resources\StickerInventory\StickerInventoryResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewStickerInventory extends RecordDetailPage
{
    protected static string $resource = StickerInventoryResource::class;
}
