<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerPayouts\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\PartnerPayouts\PartnerPayoutResource;
use App\Filament\Shared\Actions\StatementPayoutActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewPartnerPayout extends RecordDetailPage
{
    protected static string $resource = PartnerPayoutResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? StatementPayoutActions::payoutActions() : [];
    }
}
