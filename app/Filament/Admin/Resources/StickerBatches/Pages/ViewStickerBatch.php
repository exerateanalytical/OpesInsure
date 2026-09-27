<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StickerBatches\Pages;

use App\Filament\Admin\Resources\StickerBatches\StickerBatchResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewStickerBatch extends RecordDetailPage
{
    protected static string $resource = StickerBatchResource::class;
}
