<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\AccumulationActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Catastrophe events — GET catastrophe-events/{event} (catastrophe.events.view); the list reads the same tenant-scoped table. */
final class CatastropheEvents extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-cloud-lightning';

    protected static ?int $navigationSort = 76;

    protected static ?string $slug = 'risk-transfer/catastrophe-events';

    protected static array $permissions = ['catastrophe.events.view'];

    protected static string $screen = 'catastrophe_events';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(DB::table('catastrophe_events')->where('tenant_id', $t)->orderByDesc('starts_at')->limit(200)
                ->get(['id', 'code', 'name', 'peril_code', 'starts_at', 'ends_at', 'currency', 'gross_loss_minor', 'recoverable_minor', 'status'])),
            ['code' => 'text', 'name' => 'text', 'peril_code' => 'text', 'starts_at' => 'date', 'gross_loss_minor' => 'money', 'recoverable_minor' => 'money', 'status' => 'status'],
            [AccumulationActions::catDeclare()],
            [AccumulationActions::catLinkClaim(), AccumulationActions::catAggregate(), AccumulationActions::catClose()]);
    }
}
