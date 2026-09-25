<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Domain\Tenancy\TenantContext;
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
        return [PortalMetricsWidget::class];
    }
}
