<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Filament\Admin\Resources\Quotes\QuoteResource;
use Filament\Panel;
use Filament\PanelProvider;

/** REQ-UI-001 broker web portal (/broker): BROKER_* / branch-manager roles, tenant-scoped. */
final class BrokerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PortalPanelFactory::configure($panel, 'broker', [
            QuoteResource::class, PolicyResource::class, ClaimResource::class,
            // Owner decision D4 (read-only in the portal, see PortalAuthorization::PORTAL_SECTIONS)
            \App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource::class,
            \App\Filament\Admin\Resources\Bordereaux\BordereauResource::class,
            \App\Filament\Admin\Resources\CarrierSettlements\CarrierSettlementResource::class,
            \App\Filament\Admin\Resources\CommissionAccruals\CommissionAccrualResource::class,
            \App\Filament\Admin\Resources\Memberships\MembershipResource::class,
        ]);
    }
}
