<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\WebExperiences\{BrokerBookMetrics, PortalScope};
use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Models\Claim;
use BackedEnum;
use Filament\Tables\Table;

/**
 * BRK-006 Claims Dashboard: claim KPIs of the caller's book (BrokerBookMetrics::claims) and the open claims, oldest
 * first. Read = claims.view (PortalAuthorization::EQUIVALENT_READS: broker.portal.read), rows = PortalScope::narrowTable
 * 'claims' (same as /broker/claims). Each row opens the claim record (its workflow actions live there).
 */
final class ClaimsDashboardPage extends BrokerScreen
{
    protected static ?string $slug = 'claims-dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-alert';

    protected static ?int $navigationSort = 3;

    protected static array $permissions = ['claims.view'];

    protected static string $screen = 'claims_dashboard';

    protected static string $group = 'dashboards';

    public function getStats(): array
    {
        return $this->tenantId ? app(BrokerBookMetrics::class)->claims($this->tenantId) : [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PortalScope::narrowTable(Claim::query()->with(['policy.party'])->where('tenant_id', $this->tenant())->whereNull('closed_at'), 'claims')->oldest('created_at'))
            ->columns([
                self::col('claim_number')->searchable(),
                self::col('policy.policy_number', 'policy_number')->searchable(),
                self::col('policy.party.display_name', 'customer'),
                self::col('status')->badge()->formatStateUsing(fn (?string $state) => self::code('claim_status', $state)),
                self::col('loss_occurred_at', 'loss_date')->date(),
                self::col('current_reserve_minor', 'reserve')->formatStateUsing(fn ($state, Claim $r) => \App\Application\WebExperiences\Money::format((int) $state, $r->currency ?: 'XAF')),
                self::col('created_at', 'reported_at')->since(),
            ])
            ->recordUrl(fn (Claim $r) => rescue(fn () => ClaimResource::getUrl('view', ['record' => $r]), null, false))
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
