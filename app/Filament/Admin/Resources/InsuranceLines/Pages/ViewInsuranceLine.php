<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InsuranceLines\Pages;

use App\Filament\Admin\Resources\InsuranceLines\InsuranceLineResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewInsuranceLine extends RecordDetailPage
{
    protected static string $resource = InsuranceLineResource::class;
}
