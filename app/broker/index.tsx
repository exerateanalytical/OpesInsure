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
export default function BrokerHome() {
  const q = useLoad(() => BrokerApi.dashboard(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={brokerTabs}>
      <PortalHeader
        portal="broker"
        title="Broker mobile office"
        subtitle="Private client ledger and production control"
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading dashboard…"
        isEmpty={(v) => v.metrics.length === 0}
        emptyTitle="No activity yet"
        emptyMessage="Your figures will appear here once work is recorded."
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
          { label: "Customers", subtitle: "Private client ledger", icon: ContactRound, href: "/broker/clients" },
          { label: "Sales", subtitle: "Production register", icon: BookOpenCheck, href: "/broker/production" },
          { label: "Quotes", subtitle: "Quotes for your clients", icon: FileSignature, href: "/broker/quotes" },
          { label: "Policies", subtitle: "Policies you placed", icon: FileText, href: "/broker/policies" },
          { label: "Claims", subtitle: "Claims on your book", icon: ShieldAlert, href: "/broker/claims" },
          { label: "Staff", subtitle: "Team and invitations", icon: Users, href: "/broker/staff" },
          { label: "Commissions", subtitle: "Accruals and statements", icon: Wallet, href: "/broker/commissions" },
          { label: "Renewals", subtitle: "Policies due soon", icon: RefreshCw, href: "/broker/renewals" },
          { label: "Receivables", subtitle: "Amounts due to you", icon: ReceiptText, href: "/broker/receivables" },
          { label: "Compliance", subtitle: "Licences and cases", icon: BadgeCheck, href: "/broker/compliance" },
          { label: "Publications", subtitle: "Marketplace listings", icon: Store, href: "/broker/publications" },
          { label: "Account", subtitle: "Profile and security", icon: CircleUserRound, href: "/broker/account" },
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
