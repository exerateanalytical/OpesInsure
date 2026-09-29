<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\PolicyActions;
use BackedEnum;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-054 Policy Dashboard (WF-034): the caller's policy book at a glance — in force, gross written premium in force,
 * expiring within 30 days, pending issuance, suspended, lapsed / cancelled this year — and the in-force policies expiring
 * first, with the servicing actions of the policy API (PolicyActions: endorsement, cancellation request ...).
 */
final class PolicyDashboardPage extends PolicyScreen
{
    protected static string $key = 'policy_dashboard';

    protected static ?string $slug = 'policy-dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    protected static ?int $navigationSort = 70;

    public const IN_FORCE = ['ACTIVE', 'ISSUED', 'EXPIRING', 'ENDORSEMENT_PENDING', 'AMENDED'];

    protected function query(): Builder
    {
        return $this->policies(self::IN_FORCE);
    }

    protected function defaultSort(): string
    {
        return 'coverage_ends_at';
    }

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)->defaultSort('coverage_ends_at', 'asc');
    }

    protected function filters(): array
    {
        return [SelectFilter::make('status')->label(self::col('status'))->options(collect(self::IN_FORCE)->mapWithKeys(fn ($s) => [$s => \App\Filament\Shared\Columns::humanise($s)])->all())];
    }

    protected function recordActions(): array
    {
        return [PolicyActions::group()];
    }

    public function kpis(): array
    {
        return [
            self::kpi('policies_in_force', $this->policies(self::IN_FORCE)->count(), 'success'),
            self::kpi('premium_in_force', PaymentDashboardPage::fcfa((int) $this->policies(self::IN_FORCE)->sum('policies.premium_minor'))),
            self::kpi('policies_expiring', $this->policies(self::IN_FORCE)->whereBetween('policies.coverage_ends_at', [now(), now()->addDays(30)])->count(), 'warning'),
            self::kpi('policies_pending_issuance', $this->policies(['ISSUANCE_PENDING', 'PAID_PENDING_ISSUANCE', 'PENDING_PAYMENT'])->count()),
            self::kpi('policies_suspended', $this->policies(['SUSPENDED'])->count(), 'warning'),
            self::kpi('policies_lapsed_year', $this->policies(['LAPSED', 'CANCELLED'])->where('policies.updated_at', '>=', now()->startOfYear())->count(), 'danger'),
        ];
    }
}
