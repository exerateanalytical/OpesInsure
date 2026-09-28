<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionAccruals\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\CommissionAccruals\CommissionAccrualResource;
use App\Filament\Shared\Actions\CommissionActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCommissionAccrual extends RecordDetailPage
{
    protected static string $resource = CommissionAccrualResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? CommissionActions::accrualActions() : [];
    }
}
