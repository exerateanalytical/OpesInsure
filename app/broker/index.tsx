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
  ReceiptText,
  RefreshCw,
  ShieldAlert,
  Store,
  Users,
  Wallet,
} from "lucide-react-native";
import { StyleSheet, Text, View } from "react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { BrokerApi } from "@/api/client";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
export default function BrokerHome() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.dashboard(), []);
  const grid = useColumns();
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
          { label: t("portalTab_Customers"), subtitle: t("brPrivateLedger"), icon: ContactRound, href: "/broker/clients" },
          { label: t("brSales"), subtitle: t("brProductionRegister"), icon: BookOpenCheck, href: "/broker/production" },
          { label: t("agQuotes"), subtitle: t("brQuotesForClients"), icon: FileSignature, href: "/broker/quotes" },
          { label: t("policies"), subtitle: t("brPoliciesPlaced"), icon: FileText, href: "/broker/policies" },
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
const s = StyleSheet.create({
  metric: { minHeight: 96 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
