<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaInsurerAuthorizations\Pages;

use App\Filament\Admin\Resources\CimaInsurerAuthorizations\CimaInsurerAuthorizationResource;
use Filament\Resources\Pages\ListRecords;

final class ListCimaInsurerAuthorizations extends ListRecords
{
    protected static string $resource = CimaInsurerAuthorizationResource::class;

    protected function getHeaderActions(): array
    {
        return [CimaInsurerAuthorizationResource::createAction()];
    }
}
