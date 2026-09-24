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
export default function CarrierHome() {
  const q = useLoad(() => CarrierApi.dashboard(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={carrierTabs}>
      <PortalHeader
        portal="carrier"
        title="Carrier operations"
        subtitle="Underwriting, issuance, claims and settlement"
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
          { label: "Products", subtitle: "Products and tariffs", icon: Package, href: "/carrier/products" },
          { label: "Quotes & proposals", subtitle: "Proposals for your products", icon: FileSignature, href: "/carrier/proposals" },
          { label: "Underwriting", subtitle: "Referrals to decide", icon: ClipboardCheck, href: "/carrier/referrals" },
          { label: "Issuance", subtitle: "Approve or reject issuance", icon: FileCheck2, href: "/carrier/issuance" },
          { label: "Policies", subtitle: "Your policies in force", icon: FileText, href: "/carrier/policies" },
          { label: "Claims", subtitle: "Acknowledge and decide", icon: ShieldAlert, href: "/carrier/claims" },
          { label: "Payments", subtitle: "Premiums and reconciliation", icon: HandCoins, href: "/carrier/payments" },
          { label: "Distribution partners", subtitle: "Brokers and agents selling", icon: Handshake, href: "/carrier/partners" },
          { label: "Settlements", subtitle: "Premium settlements", icon: Landmark, href: "/carrier/settlements" },
          { label: "Bordereaux", subtitle: "Broker bordereaux", icon: FileSpreadsheet, href: "/carrier/bordereaux" },
          { label: "Account", subtitle: "Profile and security", icon: CircleUserRound, href: "/carrier/account" },
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
