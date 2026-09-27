import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import {
  CircleDollarSign,
  CircleUserRound,
  CloudUpload,
  ContactRound,
  FileSignature,
  FileText,
  RefreshCw,
  ShieldCheck,
  ShoppingBag,
  Store,
  UserPlus,
  Search,
  ShieldAlert,
} from "lucide-react-native";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { KpiGrid, type KpiRoute } from "@/components/portal/KpiGrid";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  Clients: { label: "kpiClients", href: "/agent/clients" },
  "Active policies": { label: "kpiActivePolicies", href: "/agent/policies" },
  "Renewals due": { label: "kpiRenewalsDue", href: "/agent/renewals" },
  "Commission available": { label: "kpiCommissionAvailable", href: "/agent/wallet" },
  "Commission pending": { label: "kpiCommissionPending", href: "/agent/wallet" },
};
export default function AgentHome() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.dashboard(), []);
  return (
    <PortalScreen tabs={agentTabs}>
      <PortalHeader
        portal="agent"
        title={t("agFieldDesk")}
        subtitle={t("agFieldDeskSubtitle")}
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
          { label: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=agent" },
          { label: t("portalTab_Leads"), subtitle: t("agProspectsToFollow"), icon: UserPlus, href: "/agent/leads" },
          { label: t("catTitle"), subtitle: t("catMenuSubtitle"), icon: Store, href: "/agent/catalogue" },
          { label: t("agQuotes"), subtitle: t("agQuotesPrepared"), icon: FileSignature, href: "/agent/quotes" },
          { label: t("portalTab_Customers"), subtitle: t("agOriginProtectedClients"), icon: ContactRound, href: "/agent/clients" },
          { label: t("policies"), subtitle: t("agClientsCover"), icon: FileText, href: "/agent/policies" },
          { label: t("ptProposals"), subtitle: t("ptProposalsSubtitle"), icon: FileSignature, href: "/agent/proposals" },
          { label: t("claims"), subtitle: t("ptClaimsSubtitle"), icon: ShieldAlert, href: "/agent/claims" },
          { label: t("notifPref_renewals"), subtitle: t("agPoliciesDueSoon"), icon: RefreshCw, href: "/agent/renewals" },
          { label: t("agCommissions"), subtitle: t("agEarningsWithdrawals"), icon: CircleDollarSign, href: "/agent/wallet" },
          { label: t("agNewSale"), subtitle: t("agQuoteAndRequest"), icon: ShoppingBag, href: "/agent/sales/new" },
          { label: t("agVerification"), subtitle: t("agIdentityMandate"), icon: ShieldCheck, href: "/agent/onboarding" },
          { label: t("agOfflineActivity"), subtitle: t("agReviewRetry"), icon: CloudUpload, href: "/agent/offline" },
          { label: t("account"), subtitle: t("agProfileSecurity"), icon: CircleUserRound, href: "/agent/account" },
        ]}
      />
    </PortalScreen>
  );
}
