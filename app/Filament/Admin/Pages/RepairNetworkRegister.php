<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use App\Filament\Shared\Actions\RepairNetworkActions;
use App\Filament\Shared\Pages\RegisterPage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Motor repair network register (garages, experts, adjusters, surveyors) with capability and source-verification actions. */
final class RepairNetworkRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-wrench';

    protected static ?string $slug = 'repair-network';

    protected static ?int $navigationSort = 95;

    protected static string $registerTable = 'provider_profiles';

    protected static array $permissions = ['providers.manage', 'providers.credential'];

    protected static string $label = 'Repair network';

    protected static ?string $group = 'Claims operations';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['category' => ['text', 'type'], 'registration_number' => ['text', 'reference'],
        'credentialing_status' => ['status', 'status'], 'data_status' => ['status', 'source'], 'created_at' => ['date', 'created']];

    public static function getNavigationLabel(): string
    {
        return __('claim_actions.repair_network.title');
    }

    public function getTitle(): string
    {
        return __('claim_actions.repair_network.title');
    }

    /** The provider master is platform-wide (no tenant column): only the repair-network categories are listed. */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        return $q->leftJoin('parties', 'parties.id', '=', 'provider_profiles.party_id')->addSelect('parties.display_name as provider_name')
            ->whereIn('provider_profiles.category', RepairNetworkService::CATEGORIES);
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([TextColumn::make('provider_name')->label(__('navigation.columns.name'))->placeholder('—'), ...$table->getColumns()])->recordActions([RepairNetworkActions::capabilities(), RepairNetworkActions::verifySource()]);
    }
}
