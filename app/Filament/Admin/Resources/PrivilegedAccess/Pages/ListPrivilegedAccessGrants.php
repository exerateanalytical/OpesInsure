<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PrivilegedAccess\Pages;

use App\Filament\Admin\Resources\PrivilegedAccess\PrivilegedAccessGrantResource;
use App\Filament\Shared\Actions\ComplianceActions;
use Filament\Resources\Pages\ListRecords;

final class ListPrivilegedAccessGrants extends ListRecords
{
    protected static string $resource = PrivilegedAccessGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [ComplianceActions::accessRequest()];
    }
}
