<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaMicroBranches\Pages;

use App\Filament\Admin\Resources\CimaMicroBranches\CimaMicroBranchResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaMicroBranches extends ListRecords
{
    protected static string $resource = CimaMicroBranchResource::class;
}
