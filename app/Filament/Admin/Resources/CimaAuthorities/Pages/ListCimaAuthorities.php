<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaAuthorities\Pages;

use App\Filament\Admin\Resources\CimaAuthorities\CimaAuthorityResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaAuthorities extends ListRecords
{
    protected static string $resource = CimaAuthorityResource::class;
}
