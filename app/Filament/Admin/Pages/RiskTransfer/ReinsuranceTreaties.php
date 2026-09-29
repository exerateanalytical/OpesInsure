<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\ReinsuranceActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Reinsurance treaties, versions and cessions — GET reinsurance/treaties (reinsurance.treaties.view). */
final class ReinsuranceTreaties extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-scroll-text';

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'risk-transfer/treaties';

    protected static array $permissions = ['reinsurance.treaties.view'];

    protected static string $screen = 'treaties';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(\App\Application\Reinsurance\TreatyService::treaties($t)->orderBy('code')->limit(500)->get()),
            ['code' => 'text', 'name' => 'text', 'treaty_type' => 'text', 'currency' => 'text', 'underwriting_year' => 'text', 'large_loss_threshold_minor' => 'money', 'status' => 'status'],
            [ReinsuranceActions::treatyCreate(), ReinsuranceActions::cessionPreview(), ReinsuranceActions::cessionCede()],
            [ReinsuranceActions::treatyAddVersion(), ReinsuranceActions::treatyActivate(), ReinsuranceActions::treatyThreshold()]);
    }
}
