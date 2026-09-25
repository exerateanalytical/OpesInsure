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
  Handshake,
  Landmark,
  Package,
  ShieldAlert,
} from "lucide-react-native";
import { StyleSheet, Text, View } from "react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { CarrierApi } from "@/api/client";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
export default function CarrierHome() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.dashboard(), []);
  const grid = useColumns();
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
          { label: t("caProducts"), subtitle: t("caProductsTariffs"), icon: Package, href: "/carrier/products" },
          { label: t("caQuotesProposals"), subtitle: t("caProposalsForProducts"), icon: FileSignature, href: "/carrier/proposals" },
          { label: t("portalTab_Underwriting"), subtitle: t("caReferralsToDecide"), icon: ClipboardCheck, href: "/carrier/referrals" },
          { label: t("caIssuance"), subtitle: t("caApproveRejectIssuance"), icon: FileCheck2, href: "/carrier/issuance" },
          { label: t("policies"), subtitle: t("caPoliciesInForce"), icon: FileText, href: "/carrier/policies" },
          { label: t("claims"), subtitle: t("caAcknowledgeDecide"), icon: ShieldAlert, href: "/carrier/claims" },
          { label: t("faqTopicPayments"), subtitle: t("caPremiumsReconciliation"), icon: HandCoins, href: "/carrier/payments" },
          { label: t("caDistributionPartners"), subtitle: t("caBrokersAgentsSelling"), icon: Handshake, href: "/carrier/partners" },
          { label: t("caSettlements"), subtitle: t("caPremiumSettlements"), icon: Landmark, href: "/carrier/settlements" },
          { label: t("caBordereaux"), subtitle: t("caBrokerBordereaux"), icon: FileSpreadsheet, href: "/carrier/bordereaux" },
          { label: t("account"), subtitle: t("agProfileSecurity"), icon: CircleUserRound, href: "/carrier/account" },
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
