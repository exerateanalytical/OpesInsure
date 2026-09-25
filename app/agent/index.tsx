import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { StyleSheet, Text, View } from "react-native";
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
  UserPlus,
  Search,
} from "lucide-react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { AgentApi } from "@/api/client";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
export default function AgentHome() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.dashboard(), []);
  const grid = useColumns();
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
        {(v) => (
          <View style={grid.row}>
            {v.metrics.map((m) => (
              <Card key={m.label} style={[s.metric, grid.item]}>
                <Text style={s.meta}>{m.label}</Text>
                <Text style={s.value}>{m.value}</Text>
              </Card>
            ))}
          </View>
        )}
      </StatePanel>
      <WorkspaceMenu
        items={[
          { label: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=agent" },
          { label: t("portalTab_Leads"), subtitle: t("agProspectsToFollow"), icon: UserPlus, href: "/agent/leads" },
          { label: t("agQuotes"), subtitle: t("agQuotesPrepared"), icon: FileSignature, href: "/agent/quotes" },
          { label: t("portalTab_Customers"), subtitle: t("agOriginProtectedClients"), icon: ContactRound, href: "/agent/clients" },
          { label: t("policies"), subtitle: t("agClientsCover"), icon: FileText, href: "/agent/policies" },
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
const s = StyleSheet.create({
  metric: { minHeight: 96 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
