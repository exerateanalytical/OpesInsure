<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Filament\Shared\Actions\KpiActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * REQ-RPT-003 KPI governance catalogue — every version of this tenant's KPI definitions (reporting.kpis.view, as GET
 * reporting/kpis). Draft → submit → approve / reject (four eyes) → retire via KpiActions (KpiCatalogueService).
 */
final class KpiCatalogue extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-gauge';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'reporting/kpi-catalogue';

    protected static array $permissions = ['reporting.kpis.view'];

    protected static string $screen = 'kpi_catalogue';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId === null ? [] : self::keyed(DB::table('kpi_definitions')->where('tenant_id', $this->tenantId)
                ->orderBy('code')->orderByDesc('version')->limit(500)->get(['id', 'code', 'version', 'name', 'query_key', 'owner', 'status', 'approved_at'])))
            ->columns([
                TextColumn::make('code')->label(self::col('code')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('name')->label(self::col('name')),
                TextColumn::make('query_key')->label(self::col('query_key')),
                TextColumn::make('owner')->label(self::col('owner')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('approved_at')->label(self::col('approved_at'))->dateTime(),
            ])
            ->headerActions([KpiActions::draft()])
            ->recordActions([KpiActions::submit(), KpiActions::approve(), KpiActions::reject(), KpiActions::retire()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
