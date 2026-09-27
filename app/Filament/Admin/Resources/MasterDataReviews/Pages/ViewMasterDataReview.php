<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataReviews\Pages;

use App\Filament\Admin\Resources\MasterDataReviews\MasterDataReviewResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMasterDataReview extends RecordDetailPage
{
    protected static string $resource = MasterDataReviewResource::class;
}
