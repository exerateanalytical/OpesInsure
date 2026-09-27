<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaMicroBranches\Pages;

use App\Filament\Admin\Resources\CimaMicroBranches\CimaMicroBranchResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaMicroBranch extends RecordDetailPage
{
    protected static string $resource = CimaMicroBranchResource::class;
}
