import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import {
  CircleUserRound,
  ClipboardCheck,
  FileCheck2,
  FileSignature,
  FileSpreadsheet,
  FileText,
  HandCoins,
  Inbox,
  Handshake,
  Landmark,
  Package,
  ShieldAlert,
  Search,
} from "lucide-react-native";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { KpiGrid, type KpiRoute } from "@/components/portal/KpiGrid";
import { useWorkspacePermissions } from "@/components/carrier/CarrierGate";
import { CARRIER_ROUTE_MODULE, canUseCarrierModule } from "@/lib/carrierAccess";
import { useCapabilities } from "@/store/capabilities";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { CarrierApi } from "@/api/client";
import { useTranslation } from "@/i18n";
/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  "Underwriting referrals": { label: "kpiReferrals", href: "/carrier/referrals" },
  "Issuance queue": { label: "kpiIssuanceQueue", href: "/carrier/issuance" },
  "Open claims": { label: "kpiOpenClaims", href: "/carrier/claims" },
  "Policies in force": { label: "kpiPoliciesInForce", href: "/carrier/policies" },
  "Settlements (net)": { label: "kpiSettlementsNet", href: "/carrier/settlements" },
};
export default function CarrierHome() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.dashboard(), []);
  // NAV-003: show only modules this workspace is granted (the server still
  // enforces every route; CarrierGate covers direct links).
  const perms = useWorkspacePermissions();
  // GET /mobile/capabilities narrows the menu further when the server sent it.
  const caps = useCapabilities((s) => s.caps);
  const allowed = (href: string) => {
    const mod = CARRIER_ROUTE_MODULE[href.split("/")[2] ?? ""];
    return !mod || canUseCarrierModule(perms, mod, caps);
  };
  const kpiRoutes = Object.fromEntries(Object.entries(KPI_ROUTES).filter(([, r]) => allowed(r.href)));
  return (
    <PortalScreen tabs={carrierTabs}>
      <PortalHeader
        portal="carrier"
        title={t("caOperations")}
        subtitle={t("caOperationsSubtitle")}
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("agLoadingDashboard")}
        isEmpty={(v) => v.metrics.length === 0}
        emptyTitle={t("agNoActivity")}
        emptyMessage={t("agNoActivityBody")}
      >
        {(v) => <KpiGrid metrics={v.metrics} routes={kpiRoutes} />}
      </StatePanel>
      <WorkspaceMenu
        items={[
          { label: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=carrier" },
          { label: t("caProducts"), subtitle: t("caProductsTariffs"), icon: Package, href: "/carrier/products" },
          { label: t("caQuotesProposals"), subtitle: t("caProposalsForProducts"), icon: FileSignature, href: "/carrier/proposals" },
          { label: t("cqrTitle"), subtitle: t("cqrMenuSubtitle"), icon: Inbox, href: "/carrier/quote-requests" },
          { label: t("portalTab_Underwriting"), subtitle: t("caReferralsToDecide"), icon: ClipboardCheck, href: "/carrier/referrals" },
          { label: t("caIssuance"), subtitle: t("caApproveRejectIssuance"), icon: FileCheck2, href: "/carrier/issuance" },
          { label: t("policies"), subtitle: t("caPoliciesInForce"), icon: FileText, href: "/carrier/policies" },
          { label: t("claims"), subtitle: t("caAcknowledgeDecide"), icon: ShieldAlert, href: "/carrier/claims" },
          { label: t("faqTopicPayments"), subtitle: t("caPremiumsReconciliation"), icon: HandCoins, href: "/carrier/payments" },
          { label: t("caDistributionPartners"), subtitle: t("caBrokersAgentsSelling"), icon: Handshake, href: "/carrier/partners" },
          { label: t("caSettlements"), subtitle: t("caPremiumSettlements"), icon: Landmark, href: "/carrier/settlements" },
          { label: t("caBordereaux"), subtitle: t("caBrokerBordereaux"), icon: FileSpreadsheet, href: "/carrier/bordereaux" },
          { label: t("account"), subtitle: t("agProfileSecurity"), icon: CircleUserRound, href: "/carrier/account" },
        ].filter((it) => allowed(it.href))}
      />
    </PortalScreen>
  );
}
