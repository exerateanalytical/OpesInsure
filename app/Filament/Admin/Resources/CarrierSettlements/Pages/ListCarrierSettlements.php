<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierSettlements\Pages;

use App\Filament\Admin\Resources\CarrierSettlements\CarrierSettlementResource;
use App\Filament\Shared\Actions\SettlementActions;
use Filament\Resources\Pages\ListRecords;

final class ListCarrierSettlements extends ListRecords
{
    protected static string $resource = CarrierSettlementResource::class;

    /** Every panel (D4 lifted 2026-09-29): gated by the API permission (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [SettlementActions::carrierSettlementPrepare(), SettlementActions::ledgerSettlementDraft()];
    }
}
