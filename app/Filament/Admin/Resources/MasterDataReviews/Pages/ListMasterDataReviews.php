<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataReviews\Pages;

use App\Filament\Admin\Resources\MasterDataReviews\MasterDataReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListMasterDataReviews extends ListRecords
{
    protected static string $resource = MasterDataReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
