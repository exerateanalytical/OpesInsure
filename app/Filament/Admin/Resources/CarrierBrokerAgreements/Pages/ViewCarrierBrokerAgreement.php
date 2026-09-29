<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages;

use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCarrierBrokerAgreement extends ViewRecord
{
    protected static string $resource = CarrierBrokerAgreementResource::class;

    /** Staff desktop only: portals stay read-only (D4). */
    protected function getHeaderActions(): array
    {
        return \App\Application\WebExperiences\PortalScope::panel() === null
            ? [\App\Filament\Shared\Actions\CarrierOnboardingActions::agreementSetProduct(), \App\Filament\Shared\Actions\CarrierOnboardingActions::agreementTransition()]
            : [];
    }
}
