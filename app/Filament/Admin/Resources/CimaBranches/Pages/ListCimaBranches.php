<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaBranches\Pages;

use App\Filament\Admin\Resources\CimaBranches\CimaBranchResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaBranches extends ListRecords
{
    protected static string $resource = CimaBranchResource::class;
}
