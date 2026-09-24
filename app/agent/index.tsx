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
} from "lucide-react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { AgentApi } from "@/api/client";
import { colors, type } from "@/theme/tokens";
export default function AgentHome() {
  const q = useLoad(() => AgentApi.dashboard(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={agentTabs}>
      <PortalHeader
        portal="agent"
        title="Agent field desk"
        subtitle="Protected clients, assisted sales and earnings"
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
          { label: "Leads", subtitle: "Prospects to follow up", icon: UserPlus, href: "/agent/leads" },
          { label: "Quotes", subtitle: "Quotes you prepared", icon: FileSignature, href: "/agent/quotes" },
          { label: "Customers", subtitle: "Origin-protected clients", icon: ContactRound, href: "/agent/clients" },
          { label: "Policies", subtitle: "Your clients' cover", icon: FileText, href: "/agent/policies" },
          { label: "Renewals", subtitle: "Policies due soon", icon: RefreshCw, href: "/agent/renewals" },
          { label: "Commissions", subtitle: "Earnings and withdrawals", icon: CircleDollarSign, href: "/agent/wallet" },
          { label: "New sale", subtitle: "Quote and request payment", icon: ShoppingBag, href: "/agent/sales/new" },
          { label: "Verification", subtitle: "Identity and mandate", icon: ShieldCheck, href: "/agent/onboarding" },
          { label: "Offline activity", subtitle: "Review and retry records", icon: CloudUpload, href: "/agent/offline" },
          { label: "Account", subtitle: "Profile and security", icon: CircleUserRound, href: "/agent/account" },
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
