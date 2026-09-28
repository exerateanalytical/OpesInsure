<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerPayouts\Pages;

use App\Filament\Admin\Resources\PartnerPayouts\PartnerPayoutResource;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerPayouts extends ListRecords
{
    protected static string $resource = PartnerPayoutResource::class;
}
