<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Reinsurance\Recoveries\RecoveryService;
use App\Filament\Shared\Actions\ReinsuranceActions;
use Filament\Tables\Table;

/** Reinsurance recoveries — GET reinsurance/recoveries via RecoveryService::index (reinsurance.recoveries.view). */
final class ReinsuranceRecoveries extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-hand-coins';

    protected static ?int $navigationSort = 73;

    protected static ?string $slug = 'risk-transfer/recoveries';

    protected static array $permissions = ['reinsurance.recoveries.view'];

    protected static string $screen = 'recoveries';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => \App\Filament\Shared\Actions\RiskTransferSupport::ownRows(self::rows(app(RecoveryService::class)->index($t)), 'claim_id', 'claims'),
            ['treaty_type' => 'text', 'currency' => 'text', 'recoverable_minor' => 'money', 'agreed_minor' => 'money', 'billed_minor' => 'money', 'settled_minor' => 'money', 'status' => 'status', 'created_at' => 'date'],
            [ReinsuranceActions::recoveryEstimate()],
            [ReinsuranceActions::recoveryNotify(), ReinsuranceActions::recoveryAgree(), ReinsuranceActions::recoveryBill()]);
    }
}
