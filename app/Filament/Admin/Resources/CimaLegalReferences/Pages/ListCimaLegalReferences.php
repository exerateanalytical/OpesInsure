<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaLegalReferences\Pages;

use App\Filament\Admin\Resources\CimaLegalReferences\CimaLegalReferenceResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaLegalReferences extends ListRecords
{
    protected static string $resource = CimaLegalReferenceResource::class;
}
