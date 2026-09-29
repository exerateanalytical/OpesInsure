<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Widgets;
use App\Filament\Shared\Widgets\PortalMetricsWidget;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

/** Dashboard shell shared by the insurer and broker portals (REQ-UI-001/002). */
final class PortalDashboard extends Dashboard
{
    public function getTitle(): string
    {
        return __('web_experience.portals.'.Filament::getCurrentOrDefaultPanel()->getId());
    }

    public function getSubheading(): ?string
    {
        $id = app(TenantContext::class)->id();

        return $id ? Tenant::query()->whereKey($id)->value('legal_name') : null;
    }

    public function getWidgets(): array
    {
        // Q5 2026-09-29: /broker home = BRK-002 operations dashboard (book KPIs) above the shared tiles.
        $broker = Filament::getCurrentOrDefaultPanel()->getId() === 'broker' ? [Widgets\BrokerOperationsWidget::class] : [];

        // Q7 2026-09-29: /insurer home = CAR-002 operations dashboard (own-carrier tiles + production / claims trends),
        // replacing the generic tiles for a user who may read the carrier dashboard (others keep the generic tiles).
        if (Filament::getCurrentOrDefaultPanel()->getId() === 'insurer' && \App\Filament\Shared\Pages\Insurer\InsurerDashboardPage::mayView('operations')) {
            return [Widgets\Insurer\InsurerStatsWidget::make(['dashboard' => 'operations']), Widgets\Insurer\InsurerChartWidget::make(['chart' => 'production_count']),
                ...(\App\Filament\Shared\Pages\Insurer\InsurerDashboardPage::mayView('claims') ? [Widgets\Insurer\InsurerChartWidget::make(['chart' => 'claims_trend'])] : []),
                Widgets\PremiumCollectedChartWidget::class, Widgets\MyWorkWidget::class,
                Widgets\ExpiringPoliciesWidget::class, Widgets\OpenClaimsWidget::class, Widgets\RecentActivityWidget::class];
        }

        return [...$broker, PortalMetricsWidget::class, Widgets\PremiumCollectedChartWidget::class, Widgets\MyWorkWidget::class,
            Widgets\ExpiringPoliciesWidget::class, Widgets\OpenClaimsWidget::class, Widgets\RecentActivityWidget::class];
    }
}
