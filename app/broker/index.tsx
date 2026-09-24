import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import {
  BadgeCheck,
  BookOpenCheck,
  ContactRound,
  ReceiptText,
  RefreshCw,
  Store,
} from "lucide-react-native";
import { StyleSheet, Text, View } from "react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { FlowRow } from "@/components/FlowPrimitives";
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
      <Card>
        <FlowRow
          icon={ContactRound}
          title="Client ledger"
          subtitle="Origin-protected corporate clients"
          onPress={() => router.push("/broker/clients")}
        />
        <FlowRow
          icon={BookOpenCheck}
          title="Production"
          subtitle="Policies issued through the brokerage"
          onPress={() => router.push("/broker/production")}
        />
        <FlowRow
          icon={RefreshCw}
          title="Renewals"
          subtitle="Upcoming expiry work queue"
          onPress={() => router.push("/broker/renewals")}
        />
        <FlowRow
          icon={ReceiptText}
          title="Receivables"
          subtitle="Amounts due and overdue"
          onPress={() => router.push("/broker/receivables")}
        />
        <FlowRow
          icon={BadgeCheck}
          title="Compliance"
          subtitle="Licence and regulatory obligations"
          onPress={() => router.push("/broker/compliance")}
        />
        <FlowRow
          icon={Store}
          title="Marketplace publications"
          subtitle="Broker products proposed for public sale"
          onPress={() => router.push("/broker/publications")}
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
