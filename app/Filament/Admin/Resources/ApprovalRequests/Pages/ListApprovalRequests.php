<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApprovalRequests\Pages;

use App\Filament\Admin\Resources\ApprovalRequests\ApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListApprovalRequests extends ListRecords
{
    protected static string $resource = ApprovalRequestResource::class;
}
