<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Capabilities\Models\CapabilityProfile;
use App\Filament\Shared\Actions\CapabilityProfileActions;
use Filament\Tables\Table;

/** Carrier capability profiles — GET carriers/{carrier}/capability-profiles (capability_profiles.view); carriers are a national register. */
final class CapabilityProfiles extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'risk-transfer/capability-profiles';

    protected static array $permissions = ['capability_profiles.view'];

    protected static string $screen = 'capability_profiles';

    protected static string $group = 'Integrations';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CapabilityProfile::query()->with('carrier'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ...static::columns(['carrier.trade_name' => 'text', 'version' => 'text', 'status' => 'status', 'maturity_code' => 'text', 'effective_from' => 'date', 'updated_at' => 'date']),
            ])
            ->recordActions([CapabilityProfileActions::capabilityReplaceModes(), CapabilityProfileActions::capabilitySubmit(),
                CapabilityProfileActions::capabilityApprove(), CapabilityProfileActions::capabilityReject()])
            ->emptyStateHeading(__('risk_transfer_actions.empty'));
    }
}
