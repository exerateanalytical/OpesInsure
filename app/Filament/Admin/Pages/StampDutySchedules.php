<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\VehiclePowerActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** Automobile stamp duty rate schedule versions (GET master-data/fiscal-power/stamp-duty-rates?all=1): new version, then approval by another user. */
final class StampDutySchedules extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-stamp';

    protected static ?int $navigationSort = 98;

    protected static ?string $slug = 'vehicle-power/stamp-duty-schedules';

    protected static array $permissions = ['vehicle_power.view'];

    protected static string $screen = 'stamp_duty';

    protected static string $group = 'vehicles';

    protected const STATUSES = ['DRAFT', 'APPROVED', 'REJECTED'];

    protected function query(string $tenantId)
    {
        return DB::table('vehicle_stamp_duty_rate_schedules')
            ->select(['id', 'schedule_code', 'version', 'status', 'effective_from', 'effective_until', 'legal_reference', 'approved_at'])
            ->orderBy('schedule_code')->orderByDesc('version');
    }

    protected function columns(): array
    {
        return ['schedule_code', 'version', 'status', 'effective_from', 'effective_until', 'legal_reference', 'approved_at'];
    }

    protected function headerWorkflowActions(): array
    {
        return [VehiclePowerActions::scheduleVersion()];
    }

    protected function recordWorkflowActions(): array
    {
        return [VehiclePowerActions::scheduleApprove()];
    }
}
