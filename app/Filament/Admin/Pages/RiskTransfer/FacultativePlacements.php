<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Reinsurance\Facultative\FacultativePlacementService;
use App\Filament\Shared\Actions\ReinsuranceActions;
use Filament\Tables\Table;

/** Facultative placements (slips) — GET reinsurance/facultative via FacultativePlacementService::list (reinsurance.facultative.view). */
final class FacultativePlacements extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-file-signature';

    protected static ?int $navigationSort = 72;

    protected static ?string $slug = 'risk-transfer/facultative';

    protected static array $permissions = ['reinsurance.facultative.view'];

    protected static string $screen = 'facultative';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => \App\Filament\Shared\Actions\RiskTransferSupport::ownRows(self::rows(app(FacultativePlacementService::class)->list($t)), 'policy_id', 'policies'),
            ['reference' => 'text', 'risk_description' => 'text', 'currency' => 'text', 'sum_insured_minor' => 'money', 'placed_share_percent' => 'text', 'status' => 'status', 'created_at' => 'date'],
            [ReinsuranceActions::facCreate()],
            [ReinsuranceActions::facLines(), ReinsuranceActions::facSubmit(), ReinsuranceActions::facApprove(), ReinsuranceActions::facReject()]);
    }
}
