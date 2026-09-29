<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Filament\Shared\Actions\RegulatoryReturnActions;
use App\Models\User;
use BackedEnum;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** REQ-RPT-006 inspection workspaces of this tenant (regulatory.inspections.view); open / approve (four eyes) / close. */
final class RegulatoryInspections extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scan-search';

    protected static ?int $navigationSort = 52;

    protected static ?string $slug = 'regulatory/inspections';

    protected static array $permissions = ['regulatory.inspections.view'];

    protected static string $screen = 'inspections';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if (! $this->ready()) {
                    return [];
                }
                $rows = DB::table('regulatory_inspections as i')->join('privileged_access_grants as g', 'g.id', '=', 'i.privileged_access_grant_id')
                    ->where('i.tenant_id', $this->tenantId)->orderByDesc('i.created_at')->limit(200)
                    ->get(['i.id', 'i.inspector_user_id', 'i.authority', 'i.reference', 'i.resources', 'i.status', 'g.starts_at', 'g.expires_at', 'i.created_at']);
                $names = User::whereIn('id', $rows->pluck('inspector_user_id')->unique())->pluck('full_name', 'id');

                return self::keyed($rows->map(fn ($r) => (array) $r + ['inspector' => $names[$r->inspector_user_id] ?? null]));
            })
            ->columns([
                self::col('authority'), self::col('reference'), self::col('inspector'), self::col('resources'),
                self::col('status')->badge(), self::col('starts_at')->dateTime(), self::col('expires_at')->dateTime(),
            ])
            ->headerActions([RegulatoryReturnActions::inspectionOpen()])
            ->recordActions([RegulatoryReturnActions::inspectionApprove(), RegulatoryReturnActions::inspectionClose()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
