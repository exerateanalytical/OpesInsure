<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaInsurerAuthorizations\Pages;

use App\Filament\Admin\Resources\CimaInsurerAuthorizations\CimaInsurerAuthorizationResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaInsurerAuthorization extends RecordDetailPage
{
    protected static string $resource = CimaInsurerAuthorizationResource::class;
}
