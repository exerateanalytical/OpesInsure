import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import {
  BadgeCheck,
  BookOpenCheck,
  CircleUserRound,
  ContactRound,
  FileSignature,
  FileText,
  PackageCheck,
  ReceiptText,
  RefreshCw,
  ShieldAlert,
  Store,
  Users,
  Wallet,
  Search,
  UserPlus,
} from "lucide-react-native";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { KpiGrid, type KpiRoute } from "@/components/portal/KpiGrid";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  Clients: { label: "kpiClients", href: "/broker/clients" },
  "Policies in force": { label: "kpiPoliciesInForce", href: "/broker/policies?f_status=ACTIVE" },
  "Premium written (12m)": { label: "kpiPremiumWritten", href: "/broker/production" },
  "Commission outstanding": { label: "kpiCommissionOutstanding", href: "/broker/commissions?f_lifecycle=unpaid" },
  "Renewals due": { label: "kpiRenewalsDue", href: "/broker/renewals" },
  "Open compliance items": { label: "kpiOpenCompliance", href: "/broker/compliance" },
};
export default function BrokerHome() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.dashboard(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <PortalHeader
        portal="broker"
        title={t("brMobileOffice")}
        subtitle={t("brMobileOfficeSubtitle")}
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("agLoadingDashboard")}
        isEmpty={(v) => v.metrics.length === 0}
        emptyTitle={t("agNoActivity")}
        emptyMessage={t("agNoActivityBody")}
      >
        {(v) => <KpiGrid metrics={v.metrics} routes={KPI_ROUTES} />}
      </StatePanel>
      <WorkspaceMenu
        items={[
          { label: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=broker" },
          { label: t("brLeads"), subtitle: t("brLeadsSubtitle"), icon: UserPlus, href: "/broker/leads" },
          { label: t("portalTab_Customers"), subtitle: t("brPrivateLedger"), icon: ContactRound, href: "/broker/clients" },
          { label: t("brSales"), subtitle: t("brProductionRegister"), icon: BookOpenCheck, href: "/broker/production" },
          { label: t("catTitle"), subtitle: t("catMenuSubtitle"), icon: PackageCheck, href: "/broker/catalogue" },
          { label: t("agQuotes"), subtitle: t("brQuotesForClients"), icon: FileSignature, href: "/broker/quotes" },
          { label: t("policies"), subtitle: t("brPoliciesPlaced"), icon: FileText, href: "/broker/policies" },
          { label: t("ptProposals"), subtitle: t("ptProposalsSubtitle"), icon: FileSignature, href: "/broker/proposals" },
          { label: t("claims"), subtitle: t("brClaimsOnBook"), icon: ShieldAlert, href: "/broker/claims" },
          { label: t("brStaff"), subtitle: t("brTeamInvitations"), icon: Users, href: "/broker/staff" },
          { label: t("agCommissions"), subtitle: t("brAccrualsStatements"), icon: Wallet, href: "/broker/commissions" },
          { label: t("notifPref_renewals"), subtitle: t("agPoliciesDueSoon"), icon: RefreshCw, href: "/broker/renewals" },
          { label: t("brReceivables"), subtitle: t("brAmountsDue"), icon: ReceiptText, href: "/broker/receivables" },
          { label: t("brComplianceShort"), subtitle: t("brLicencesCases"), icon: BadgeCheck, href: "/broker/compliance" },
          { label: t("brPublications"), subtitle: t("brMarketplaceListings"), icon: Store, href: "/broker/publications" },
          { label: t("account"), subtitle: t("agProfileSecurity"), icon: CircleUserRound, href: "/broker/account" },
        ]}
      />
    </PortalScreen>
  );
}
