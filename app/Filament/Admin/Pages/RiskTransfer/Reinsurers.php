<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\ReinsuranceActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Reinsurers and reinsurance brokers — GET reinsurance/reinsurers (reinsurance.treaties.view). */
final class Reinsurers extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'risk-transfer/reinsurers';

    protected static array $permissions = ['reinsurance.treaties.view'];

    protected static string $screen = 'reinsurers';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(DB::table('reinsurers')->where('tenant_id', $t)->orderBy('code')->limit(500)->get()),
            ['code' => 'text', 'name' => 'text', 'role' => 'text', 'rating' => 'text', 'status' => 'status', 'approved_security_status' => 'status'],
            [ReinsuranceActions::reinsurerCreate()],
            [ReinsuranceActions::reinsurerStatus(), ReinsuranceActions::reinsurerSecurity()]);
    }
}
