<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerPayouts\Pages;

use App\Filament\Admin\Resources\PartnerPayouts\PartnerPayoutResource;
use App\Filament\Shared\Actions\StatementPayoutActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewPartnerPayout extends RecordDetailPage
{
    protected static string $resource = PartnerPayoutResource::class;

    /** Every panel (D4 lifted 2026-09-29): each action is gated by its API permission + own-organisation record (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return StatementPayoutActions::payoutActions();
    }
}
