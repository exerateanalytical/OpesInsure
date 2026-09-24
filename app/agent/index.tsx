import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import {
  CircleDollarSign,
  CloudUpload,
  ContactRound,
  RefreshCw,
  ShieldCheck,
  ShoppingBag,
} from "lucide-react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { FlowRow } from "@/components/FlowPrimitives";
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
      <Card>
        <FlowRow
          icon={ShieldCheck}
          title="Agent verification"
          subtitle="Identity, mandate and compliance"
          onPress={() => router.push("/agent/onboarding")}
        />
        <FlowRow
          icon={ContactRound}
          title="Client portfolio"
          subtitle="Register and serve origin-protected clients"
          onPress={() => router.push("/agent/clients")}
        />
        <FlowRow
          icon={ShoppingBag}
          title="New assisted sale"
          subtitle="Quote and request payment from the client"
          onPress={() => router.push("/agent/sales/new")}
        />
        <FlowRow
          icon={RefreshCw}
          title="Renewals"
          subtitle="Policies requiring field follow-up"
          onPress={() => router.push("/agent/renewals")}
        />
        <FlowRow
          icon={CircleDollarSign}
          title="Commission wallet"
          subtitle="Available, pending and reversed earnings"
          onPress={() => router.push("/agent/wallet")}
        />
        <FlowRow
          icon={CloudUpload}
          title="Offline activity"
          subtitle="Review and retry field records"
          onPress={() => router.push("/agent/offline")}
        />
      </Card>
    </PortalScreen>
  );
}
const s = StyleSheet.create({
  metric: { minHeight: 96 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
