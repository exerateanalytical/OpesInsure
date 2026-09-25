<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseRecords\Pages;

use App\Filament\Admin\Resources\CaseRecords\CaseRecordResource;
use Filament\Resources\Pages\ListRecords;

final class ListCaseRecords extends ListRecords
{
    protected static string $resource = CaseRecordResource::class;
}
