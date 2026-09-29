<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages;

use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use Filament\Resources\Pages\ListRecords;

final class ListCarrierBrokerAgreements extends ListRecords
{
    protected static string $resource = CarrierBrokerAgreementResource::class;

    /** Every panel (D4 lifted 2026-09-29): API permission; the insurer portal offers only its own carrier (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\CarrierOnboardingActions::agreementCreate()];
    }
}
