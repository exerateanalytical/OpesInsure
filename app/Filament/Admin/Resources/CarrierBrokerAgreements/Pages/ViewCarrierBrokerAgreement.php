<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages;

use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCarrierBrokerAgreement extends ViewRecord
{
    protected static string $resource = CarrierBrokerAgreementResource::class;

    /** Every panel (D4 lifted 2026-09-29): API permission + own agreement (insurer: own carrier_id; docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\CarrierOnboardingActions::agreementSetProduct(), \App\Filament\Shared\Actions\CarrierOnboardingActions::agreementTransition()];
    }
}
