<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\VehiclePowerActions;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** VPWR-009 transport licences: recorded by a maker, verified VALID by a different user. */
final class TransportLicences extends GovernanceRegisterPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-bus';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'vehicle-power/transport-licences';

    protected static array $permissions = ['vehicle_power.view', 'vehicle_power.fiscal.submit', 'vehicle_power.fiscal.verify'];

    protected static string $screen = 'licences';

    protected static string $group = 'vehicles';

    protected const STATUSES = ['PENDING', 'VALID', 'EXPIRED', 'SUSPENDED', 'REVOKED', 'REJECTED'];

    protected function query(string $tenantId)
    {
        return DB::table('vehicle_transport_licences')->where('tenant_id', $tenantId)->orderByDesc('created_at');
    }

    protected function columns(): array
    {
        return ['registration_number', 'licence_number', 'licence_type', 'valid_from', 'valid_until', 'status'];
    }

    protected function headerWorkflowActions(): array
    {
        return [VehiclePowerActions::recordLicence()];
    }

    protected function recordWorkflowActions(): array
    {
        return [VehiclePowerActions::decideLicence()];
    }
}
