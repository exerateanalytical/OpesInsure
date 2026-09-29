<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Actions\ProviderPortalActions;
use App\Filament\Shared\Pages\RegisterPage;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Health benefit schedules (GET health/benefit-schedules) with the desk actions: new schedule line, eligibility check and
 * health-card scan — ProviderPortalActions, same services/permissions as the API. Eligibility results show coverage
 * flags only, never medical history.
 */
final class HealthBenefitSchedules extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-table-2';

    protected static ?string $slug = 'health/benefit-schedules';

    protected static ?int $navigationSort = 58;

    protected static string $registerTable = 'health_benefit_schedules';

    protected static array $permissions = ['health.benefits.view', 'health.benefits.manage', 'health.eligibility.check', 'health.eligibility.scan'];

    protected static string $label = 'Benefit schedules';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['benefit_code' => ['text', 'reference'], 'period_basis' => ['text', 'type'], 'period_limit_minor' => ['money', 'rate'],
        'effective_from' => ['day', 'effective_from'], 'effective_until' => ['day', 'effective_until'], 'status' => ['status', 'status']];

    public static function getNavigationGroup(): ?string
    {
        return __('workflow_actions.screens.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider_portal_actions.screens.benefit_schedules');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** Product-wide rows (tenant_id null) and the tenant's own, as the API lists them. */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('health_benefit_schedules.tenant_id')->orWhere('health_benefit_schedules.tenant_id', $tenantId));
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->headerActions([ProviderPortalActions::benefitScheduleCreate(), ProviderPortalActions::eligibilityCheck(), ProviderPortalActions::eligibilityScan()]);
    }
}
