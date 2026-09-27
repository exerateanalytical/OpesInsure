<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Devices\Pages;

use App\Filament\Admin\Resources\Devices\DeviceResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDevice extends RecordDetailPage
{
    protected static string $resource = DeviceResource::class;
}
