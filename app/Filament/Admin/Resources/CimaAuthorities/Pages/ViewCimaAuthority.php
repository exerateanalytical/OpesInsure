<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaAuthorities\Pages;

use App\Filament\Admin\Resources\CimaAuthorities\CimaAuthorityResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCimaAuthority extends RecordDetailPage
{
    protected static string $resource = CimaAuthorityResource::class;
}
