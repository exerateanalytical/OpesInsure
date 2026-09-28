<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierSettlements\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\CarrierSettlements\CarrierSettlementResource;
use App\Filament\Shared\Actions\SettlementActions;
use Filament\Resources\Pages\ListRecords;

final class ListCarrierSettlements extends ListRecords
{
    protected static string $resource = CarrierSettlementResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? [SettlementActions::carrierSettlementPrepare(), SettlementActions::ledgerSettlementDraft()] : [];
    }
}
