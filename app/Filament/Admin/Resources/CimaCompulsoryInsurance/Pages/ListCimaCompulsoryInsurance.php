<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaCompulsoryInsurance\Pages;

use App\Filament\Admin\Resources\CimaCompulsoryInsurance\CimaCompulsoryInsuranceResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaCompulsoryInsurance extends ListRecords
{
    protected static string $resource = CimaCompulsoryInsuranceResource::class;
}
