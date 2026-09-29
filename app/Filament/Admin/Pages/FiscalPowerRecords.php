<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\VehiclePowerActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** Fiscal power records (variant rows are platform-wide, registration rows belong to the tenant): maker-checker review. */
final class FiscalPowerRecords extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-gauge';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'vehicle-power/fiscal-records';

    protected static array $permissions = ['vehicle_power.view'];

    protected static string $screen = 'fiscal_records';

    protected static string $group = 'vehicles';

    protected function query(string $tenantId)
    {
        return DB::table('vehicle_fiscal_power_records')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->select(['id', 'variant_id', 'registration_number', 'vin', 'version', 'fiscal_power_cv', 'fiscal_power_band_code', 'source_type', 'source_reference', 'review_state', 'verification_status', 'created_at'])
            ->orderByDesc('created_at');
    }

    protected function columns(): array
    {
        return ['registration_number', 'vin', 'version', 'fiscal_power_cv', 'fiscal_power_band_code', 'source_type', 'source_reference', 'review_state'];
    }

    protected function headerWorkflowActions(): array
    {
        return [VehiclePowerActions::submitRecord()];
    }

    protected function recordWorkflowActions(): array
    {
        return [VehiclePowerActions::attachSource(), VehiclePowerActions::verifyRecord(), VehiclePowerActions::rejectRecord()];
    }
}
