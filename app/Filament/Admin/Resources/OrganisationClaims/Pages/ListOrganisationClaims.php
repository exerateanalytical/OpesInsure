<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OrganisationClaims\Pages;

use App\Filament\Admin\Resources\OrganisationClaims\OrganisationClaimResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrganisationClaims extends ListRecords
{
    protected static string $resource = OrganisationClaimResource::class;

    public function getSubheading(): ?string
    {
        return __('org_claim.admin.intro');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
