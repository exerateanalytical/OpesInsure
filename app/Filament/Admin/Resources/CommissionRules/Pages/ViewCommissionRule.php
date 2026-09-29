<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionRules\Pages;

use App\Filament\Admin\Resources\CommissionRules\CommissionRuleResource;
use App\Filament\Shared\Actions\CommissionActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCommissionRule extends RecordDetailPage
{
    protected static string $resource = CommissionRuleResource::class;

    /** Every panel (D4 lifted 2026-09-29): each action is gated by its API permission + own-organisation record (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [CommissionActions::ruleApprove()];
    }
}
