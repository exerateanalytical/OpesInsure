<?php

declare(strict_types=1);

namespace App\Application\Help;

use Filament\Facades\Filament;

/**
 * S11 contextual help: a "?" link in the page header of the key screens (PAGE_HEADER_ACTIONS_BEFORE render hook, scoped
 * by the page and resource class), pointing at the matching section of the guide for the signed-in user's role.
 * MAP: page or resource class => candidate [guide, anchor] pairs in preference order; the user's own guide wins when it
 * is a candidate, else the first candidate offered in the current panel.
 */
final class HelpContext
{
    private const R = 'App\\Filament\\Admin\\Resources\\';

    private const P = 'App\\Application\\Providers\\Workspace\\Filament\\Pages\\';

    /** @var array<string, list<array{0: string, 1: string}>> */
    public const MAP = [
        // Sales and servicing (broker, insurer, admin panels)
        self::R.'Quotes\\QuoteResource' => [['broker_staff', 'quotes'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book'], ['underwriter', 'quotes'], ['insurer_admin', 'portfolio']],
        self::R.'Proposals\\ProposalResource' => [['broker_staff', 'proposals'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book']],
        self::R.'Policies\\PolicyResource' => [['broker_staff', 'policies'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book'], ['underwriter', 'issuance'], ['claims_handler', 'claims-queue'], ['insurer_admin', 'portfolio'], ['platform_admin', 'policies-claims']],
        self::R.'Renewals\\RenewalResource' => [['broker_staff', 'renewals'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book']],
        self::R.'Claims\\ClaimResource' => [['claims_handler', 'claims-queue'], ['broker_staff', 'claims'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book'], ['insurer_admin', 'portfolio'], ['platform_admin', 'policies-claims']],
        self::R.'UnderwritingCases\\UnderwritingCaseResource' => [['underwriter', 'cases']],
        self::R.'ApprovalRequests\\ApprovalRequestResource' => [['underwriter', 'approvals'], ['claims_handler', 'approvals'], ['insurer_admin', 'approvals'], ['platform_admin', 'policies-claims']],
        self::R.'InsuranceProducts\\InsuranceProductResource' => [['insurer_admin', 'products'], ['platform_admin', 'products']],
        self::R.'TariffVersions\\TariffVersionResource' => [['insurer_admin', 'products'], ['platform_admin', 'products']],
        // People
        self::R.'Memberships\\MembershipResource' => [['broker_admin', 'staff'], ['platform_admin', 'users']],
        self::R.'Invitations\\InvitationResource' => [['insurer_admin', 'users'], ['platform_admin', 'users']],
        self::R.'Tenants\\TenantResource' => [['platform_admin', 'tenants']],
        // Money
        self::R.'CommissionAccruals\\CommissionAccrualResource' => [['finance', 'commissions']],
        self::R.'PartnerPayouts\\PartnerPayoutResource' => [['finance', 'commissions']],
        self::R.'CarrierSettlements\\CarrierSettlementResource' => [['finance', 'settlements']],
        self::R.'Reconciliations\\ReconciliationResource' => [['finance', 'reconciliation'], ['platform_admin', 'finance']],
        // Broker portal pages
        'App\\Filament\\Shared\\Pages\\BrokerCustomersPage' => [['broker_staff', 'customers'], ['broker_admin', 'sales'], ['branch_manager', 'branch-book']],
        'App\\Filament\\Shared\\Pages\\BranchOverviewPage' => [['branch_manager', 'overview']],
        // Provider portal
        self::P.'EligibilityPage' => [['provider', 'eligibility']],
        self::P.'PreauthorizationsPage' => [['provider', 'preauthorizations']],
        self::P.'ClaimsPage' => [['provider', 'claims']],
        self::P.'SettlementsPage' => [['provider', 'settlements']],
    ];

    /** @return array{0: string, 1: string}|null [guide, anchor] for the scopes in $surface for the signed-in user. */
    public static function target(array $scopes, string $surface): ?array
    {
        $offered = HelpGuides::SURFACES[$surface] ?? [];
        foreach ($scopes as $scope) {
            $candidates = self::MAP[ltrim((string) $scope, '\\')] ?? null;
            if ($candidates === null) {
                continue;
            }
            $mine = HelpGuides::resolve($surface, auth()->user());
            foreach ($candidates as $c) {
                if ($c[0] === $mine) {
                    return $c;
                }
            }
            foreach ($candidates as $c) {
                if (in_array($c[0], $offered, true)) {
                    return $c;
                }
            }
        }

        return null;
    }

    public static function link(array $scopes): string
    {
        return (string) rescue(function () use ($scopes): string {
            $surface = (string) (Filament::getCurrentPanel()?->getId() ?? '');
            $t = self::target($scopes, $surface);
            if ($t === null) {
                return '';
            }
            $url = e(HelpGuides::url($surface, $t[0], $t[1]));
            $label = e(__('help_guides.context_link'));

            return '<a href="'.$url.'" data-help-link title="'.$label.'" aria-label="'.$label.'" '
                .'style="display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;border:1px solid #AFCDF7;color:#1256B8;font-weight:700;text-decoration:none;background:#fff">?</a>';
        }, '', false);
    }
}
