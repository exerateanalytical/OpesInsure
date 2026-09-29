<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use App\Filament\Shared\Actions\ProviderPortalActions;
use App\Filament\Shared\Pages\RegisterPage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Provider registry (hospitals, clinics, pharmacies, labs): register, credential, facilities, facility services, code
 * mappings, relationships and the medical-service catalogue — ProviderPortalActions, same services/permissions as the API.
 * Repair-network categories have their own register (RepairNetworkRegister).
 */
final class HealthProviderRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-hospital';

    protected static ?string $slug = 'health/providers';

    protected static ?int $navigationSort = 59;

    protected static string $registerTable = 'provider_profiles';

    protected static array $permissions = ['providers.view', 'providers.manage', 'providers.credential'];

    protected static string $label = 'Health providers';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['category' => ['text', 'type'], 'registration_number' => ['text', 'reference'],
        'credentialing_status' => ['status', 'status'], 'created_at' => ['date', 'created']];

    public static function getNavigationGroup(): ?string
    {
        return __('workflow_actions.screens.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider_portal_actions.screens.provider_register');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** The provider master is platform-wide (no tenant column). */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        return $q->leftJoin('parties', 'parties.id', '=', 'provider_profiles.party_id')->addSelect('parties.display_name as provider_name')
            ->whereNotIn('provider_profiles.category', RepairNetworkService::CATEGORIES);
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([TextColumn::make('provider_name')->label(__('navigation.columns.name'))->placeholder('—'), ...$table->getColumns()])
            ->headerActions([ProviderPortalActions::providerRegister(), ProviderPortalActions::medicalServiceAdd()])
            ->recordActions([ProviderPortalActions::providerCredential(), ProviderPortalActions::providerAddFacility(), ProviderPortalActions::providerAddFacilityService(),
                ProviderPortalActions::providerMapCode(), ProviderPortalActions::providerRelate()]);
    }
}
