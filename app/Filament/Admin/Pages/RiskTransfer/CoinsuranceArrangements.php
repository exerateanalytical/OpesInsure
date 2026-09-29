<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Coinsurance\CoinsuranceService;
use App\Filament\Shared\Actions\CoinsuranceActions;
use Filament\Tables\Table;

/** Co-insurance arrangements — GET coinsurance/arrangements via CoinsuranceService::list (coinsurance.view). */
final class CoinsuranceArrangements extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-handshake';

    protected static ?int $navigationSort = 74;

    protected static ?string $slug = 'risk-transfer/coinsurance';

    protected static array $permissions = ['coinsurance.view'];

    protected static string $screen = 'coinsurance';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => \App\Filament\Shared\Actions\RiskTransferSupport::ownRows(self::rows(app(CoinsuranceService::class)->list($t)), 'policy_id', 'policies'),
            ['reference' => 'text', 'currency' => 'text', 'settlement_method' => 'text', 'effective_from' => 'text', 'effective_until' => 'text', 'status' => 'status'],
            [CoinsuranceActions::coCreate()],
            [CoinsuranceActions::coActivate(), CoinsuranceActions::coPreview(), CoinsuranceActions::coApportion(), CoinsuranceActions::coTerminate()]);
    }
}
