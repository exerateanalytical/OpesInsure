<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierSettlements\Pages;

use App\Filament\Admin\Resources\CarrierSettlements\CarrierSettlementResource;
use App\Filament\Shared\Actions\SettlementActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCarrierSettlement extends RecordDetailPage
{
    protected static string $resource = CarrierSettlementResource::class;

    /** Every panel (D4 lifted 2026-09-29): each action is gated by its API permission + own-organisation record (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return SettlementActions::recordActions();
    }
}
