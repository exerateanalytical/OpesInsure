<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerApplications\Pages;

use App\Filament\Admin\Resources\PartnerApplications\PartnerApplicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerApplications extends ListRecords
{
    protected static string $resource = PartnerApplicationResource::class;

    public function getSubheading(): ?string
    {
        return __('partner_apply.admin.intro');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
