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
            // UI audit 2026-09-27: same admin resources, gated in the portal by PortalAuthorization (read-only sections)
            // or by their own permission checks (approval inbox: approvals.inbox.view / approvals.decide).
            \App\Filament\Admin\Resources\ApprovalRequests\ApprovalRequestResource::class,
            \App\Filament\Admin\Resources\Quotes\QuoteResource::class,
            \App\Filament\Admin\Resources\PolicyIssuances\PolicyIssuanceResource::class,
            \App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource::class,
            \App\Filament\Admin\Resources\StickerBatches\StickerBatchResource::class,
            \App\Filament\Admin\Resources\StickerInventory\StickerInventoryResource::class,
            \App\Filament\Admin\Resources\Journals\JournalResource::class,
            \App\Filament\Admin\Resources\CommissionAccruals\CommissionAccrualResource::class,
        ], [
            // Read-only registers (no model/resource yet; writes stay in the APIs).
            \App\Filament\Shared\Pages\Registers\QuoteRequestsRegister::class,
            \App\Filament\Shared\Pages\Registers\ReferralsRegister::class,
            \App\Filament\Shared\Pages\Registers\CoinsuranceRegister::class,
            \App\Filament\Shared\Pages\Registers\ReinsuranceTreatiesRegister::class,
            \App\Filament\Shared\Pages\Registers\ReinsuranceCessionsRegister::class,
            \App\Filament\Shared\Pages\Registers\KycRegister::class,
            \App\Filament\Shared\Pages\Registers\CashierSessionsRegister::class,
            \App\Filament\Shared\Pages\Registers\FxRatesRegister::class,
            // Health section (carrier staff): same pages and permissions as the admin panel (HealthQueuePage::canAccess).
            \App\Filament\Admin\Pages\HealthPreauthorizationQueue::class,
            \App\Filament\Admin\Pages\HealthProviderClaimQueue::class,
            \App\Filament\Admin\Pages\HealthProviderSettlements::class,
            \App\Filament\Admin\Pages\HealthProviderDisputes::class,
            // Insurer letterhead and public logo (carrier-scoped, maker-checker), feeding logo_url / carrier_logo_url.
            \App\Filament\Shared\Pages\InsurerLetterheadPage::class,
        ]);
    }
}
