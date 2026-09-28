<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionRules\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\CommissionRules\CommissionRuleResource;
use App\Filament\Shared\Actions\CommissionActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCommissionRule extends RecordDetailPage
{
    protected static string $resource = CommissionRuleResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? [CommissionActions::ruleApprove()] : [];
    }
}
