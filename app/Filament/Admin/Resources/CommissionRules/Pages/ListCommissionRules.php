<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionRules\Pages;

use App\Filament\Admin\Resources\CommissionRules\CommissionRuleResource;
use App\Filament\Shared\Actions\CommissionActions;
use Filament\Resources\Pages\ListRecords;

final class ListCommissionRules extends ListRecords
{
    protected static string $resource = CommissionRuleResource::class;

    /** Every panel (D4 lifted 2026-09-29): gated by the API permission (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [CommissionActions::ruleCreate()];
    }
}
