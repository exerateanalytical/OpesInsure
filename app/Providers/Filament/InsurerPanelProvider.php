<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Filament\Admin\Resources\Policies\PolicyResource;
use Filament\Panel;
use Filament\PanelProvider;

/** REQ-UI-001 insurer web portal (/insurer): CARRIER_* / underwriting / adjuster roles, tenant-scoped. */
final class InsurerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PortalPanelFactory::configure($panel, 'insurer', [
            PolicyResource::class, ClaimResource::class,
            // Owner decision D4 (read-only in the portal, see PortalAuthorization::PORTAL_SECTIONS)
            \App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource::class,
            \App\Filament\Admin\Resources\Bordereaux\BordereauResource::class,
            \App\Filament\Admin\Resources\CarrierSettlements\CarrierSettlementResource::class,
        ]);
    }
}
