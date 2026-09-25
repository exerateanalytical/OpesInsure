<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkQueues\Pages;

use App\Filament\Admin\Resources\WorkQueues\WorkQueueResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateWorkQueue extends CreateRecord
{
    protected static string $resource = WorkQueueResource::class;
}
