<?php
namespace App\Filament\Admin\Resources\Partners\Pages;

use App\Filament\Admin\Resources\Partners\PartnerResource;
use App\Filament\Shared\Actions\PartnerActions;
use Filament\Resources\Pages\ViewRecord;

final class ViewPartner extends ViewRecord
{
    protected static string $resource = PartnerResource::class;

    /** Onboarding checklist, activate / suspend (partners.manage, as POST partners/{p}/status) and the carrier agreements. */
    protected function getHeaderActions(): array
    {
        return [PartnerActions::group()];
    }
}
