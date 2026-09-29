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
            \App\Filament\Admin\Resources\IssuanceExceptions\IssuanceExceptionResource::class,
            \App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource::class,
            // P3 (owner 2026-09-29): the insurer's own products and tariff versions (own carrier, catalogue.* / tariff.*
            // permissions; governance and CIMA guard stay in the services).
            \App\Filament\Admin\Resources\InsuranceProducts\InsuranceProductResource::class,
            \App\Filament\Admin\Resources\TariffVersions\TariffVersionResource::class,
            \App\Filament\Admin\Resources\StickerBatches\StickerBatchResource::class,
            \App\Filament\Admin\Resources\StickerInventory\StickerInventoryResource::class,
            \App\Filament\Admin\Resources\Journals\JournalResource::class,
            \App\Filament\Admin\Resources\CommissionAccruals\CommissionAccrualResource::class,
            // P4 (owner 2026-09-29): staff invitations (identity.invite, own tenant/carrier, no role escalation) and the
            // document register (own carrier's documents; documents.* permissions).
            \App\Filament\Admin\Resources\Invitations\InvitationResource::class,
            \App\Filament\Admin\Resources\Memberships\MembershipResource::class,
            \App\Filament\Admin\Resources\GeneratedDocuments\GeneratedDocumentResource::class,
        ], [
            // Read-only registers (no model/resource yet; writes stay in the APIs).
            \App\Filament\Shared\Pages\Registers\QuoteRequestsRegister::class,
            \App\Filament\Shared\Pages\Registers\ReferralsRegister::class,
            // Q8 (2026-09-29): underwriting workbench dashboard (UND-001/004/020), own carrier, case read permissions.
            \App\Filament\Admin\Pages\Underwriting\UnderwritingDashboard::class,
            // P4 (owner 2026-09-29, D4 lifted): the risk-transfer workbench replaces the read-only treaty / co-insurance
            // registers — same pages as /admin, gated by the API permission, own tenant; policy/claim-linked rows and
            // pickers narrowed to the caller's carrier (RiskTransferSupport::ownRows / policySelect / claimSelect).
            \App\Filament\Admin\Pages\RiskTransfer\Reinsurers::class,
            \App\Filament\Admin\Pages\RiskTransfer\ReinsuranceTreaties::class,
            \App\Filament\Admin\Pages\RiskTransfer\FacultativePlacements::class,
            \App\Filament\Admin\Pages\RiskTransfer\ReinsuranceRecoveries::class,
            \App\Filament\Admin\Pages\RiskTransfer\CoinsuranceArrangements::class,
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
            // S10: bulk agreement setup and coverage matrix, own carrier only (BulkAgreements::scope), agreement API permissions.
            \App\Filament\Admin\Pages\Distribution\BulkAgreements::class,
            \App\Filament\Admin\Pages\Distribution\AgreementCoverage::class,
            // Q7 carrier screens (CAR-003…007 dashboards, 012 intermediaries, 018 eligibility, 022 rating, 029 cancellations):
            // own carrier only (InsurerDashboards / PortalScope), gated by the API read permission of each screen.
            \App\Filament\Shared\Pages\Insurer\ProductionDashboard::class,
            \App\Filament\Shared\Pages\Insurer\PortfolioDashboard::class,
            \App\Filament\Shared\Pages\Insurer\ClaimsPerformanceDashboard::class,
            \App\Filament\Shared\Pages\Insurer\BrokerProductionDashboard::class,
            \App\Filament\Shared\Pages\Insurer\ProductPerformanceDashboard::class,
            \App\Filament\Shared\Pages\Insurer\IntermediaryOverview::class,
            \App\Filament\Shared\Pages\Insurer\EligibilityRules::class,
            \App\Filament\Shared\Pages\Insurer\RatingRules::class,
            \App\Filament\Shared\Pages\Insurer\CancellationReview::class,
            // Q9 claims professional / adjuster workbench (CLP-001…018): own assignments, own carrier's claims.
            \App\Filament\Shared\Pages\ClaimsWorkbench\ClaimsWorkbenchDashboard::class,
            \App\Filament\Shared\Pages\ClaimsWorkbench\MyAssignments::class,
            \App\Filament\Shared\Pages\ClaimsWorkbench\CompletedAssignments::class,
            \App\Filament\Shared\Pages\ClaimsWorkbench\AssignmentWorkbench::class,
        ]);
    }
}
