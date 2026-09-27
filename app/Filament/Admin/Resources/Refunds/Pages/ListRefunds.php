<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Refunds\Pages;

use App\Filament\Admin\Resources\Refunds\RefundResource;
use Filament\Resources\Pages\ListRecords;

final class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;
}
