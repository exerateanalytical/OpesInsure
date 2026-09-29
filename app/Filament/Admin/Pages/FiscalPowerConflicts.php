<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\VehiclePowerActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** Fiscal power conflict cases: a checker keeps the verified value or accepts the challenger. */
final class FiscalPowerConflicts extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-git-compare';

    protected static ?int $navigationSort = 97;

    protected static ?string $slug = 'vehicle-power/conflicts';

    protected static array $permissions = ['vehicle_power.view'];

    protected static string $screen = 'fiscal_conflicts';

    protected static string $group = 'vehicles';

    protected const STATUSES = ['OPEN', 'RESOLVED'];

    protected function query(string $tenantId)
    {
        return DB::table('vehicle_fiscal_power_conflicts as c')->join('vehicle_fiscal_power_records as r', 'r.id', '=', 'c.record_id')
            ->where(fn ($q) => $q->where('r.tenant_id', $tenantId)->orWhereNull('r.tenant_id'))
            ->select(['c.id', 'c.record_id', 'c.status', 'c.values', 'c.resolution', 'c.created_at', 'r.registration_number', 'r.fiscal_power_cv'])
            ->orderByDesc('c.created_at');
    }

    protected function columns(): array
    {
        return ['registration_number', 'fiscal_power_cv', 'values', 'status', 'resolution', 'created_at'];
    }

    protected function recordWorkflowActions(): array
    {
        return [VehiclePowerActions::resolveConflict()];
    }
}
