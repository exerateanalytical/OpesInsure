<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Filament\Admin\Resources\Quotes\QuoteResource;
use App\Models\User;
use Filament\Navigation\{NavigationGroup, NavigationItem};
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
        ])
            // UI audit 2026-09-27: the shared finance resources use the literal group key 'Financial operations';
            // give it a translated label here (broker panel only, the admin grouping is untouched).
            ->navigationGroups([
                'Financial operations' => NavigationGroup::make('Financial operations')->label(fn (): string => __('partner_portal.financial_operations')),
                'partner_workspace' => NavigationGroup::make('partner_workspace')->label(fn (): string => __('partner_portal.workspace.group')),
            ])
            ->navigationItems(self::workspaceItems());
    }

    /**
     * UI audit 2026-09-27: the portal is read-only (owner decision D4), so client onboarding, quoting for the
     * partner's own book, leads and commission statements had no entry point here. They live in the partner
     * workspace of the web account area (/account/*, same APIs as the mobile app, rows scoped to the caller's
     * own book by PartnerBook / PartyResolver). These are links only; each is shown to the roles whose
     * permission the target page's API already requires, and grants nothing.
     *
     * @return list<NavigationItem>
     */
    public static function workspaceItems(): array
    {
        $can = fn (string ...$permissions): bool => ($u = auth()->user()) instanceof User
            && collect($permissions)->contains(fn (string $p): bool => (bool) rescue(fn () => $u->hasPermission($p), false, false));

        return [
            NavigationItem::make('workspace_clients')->label(fn (): string => __('partner_portal.workspace.clients'))->url('/account/customers')
                ->icon('lucide-users')->group('partner_workspace')->sort(1)->visible(fn (): bool => $can('broker.portal.read')),
            NavigationItem::make('workspace_new_client')->label(fn (): string => __('partner_portal.workspace.new_client'))->url('/account/buy?new_client=1')
                ->icon('lucide-user-plus')->group('partner_workspace')->sort(2)->visible(fn (): bool => $can('crm.leads.manage')),
            NavigationItem::make('workspace_new_quote')->label(fn (): string => __('partner_portal.workspace.new_quote'))->url('/account/buy')
                ->icon('lucide-file-plus')->group('partner_workspace')->sort(3)->visible(fn (): bool => $can('quotes.manage')),
            NavigationItem::make('workspace_leads')->label(fn (): string => __('partner_portal.workspace.leads'))->url('/account/leads')
                ->icon('lucide-target')->group('partner_workspace')->sort(4)->visible(fn (): bool => $can('crm.leads.read')),
            NavigationItem::make('workspace_commissions')->label(fn (): string => __('partner_portal.workspace.commissions'))->url('/account/commissions')
                ->icon('lucide-piggy-bank')->group('partner_workspace')->sort(5)->visible(fn (): bool => $can('broker.finance.read')),
        ];
    }
}
