import React, { useEffect, useState } from "react";
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
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { BrokerApi } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";
export default function BrokerHome() {
  const [d, setD] = useState<{
    metrics: { label: string; value: string; tone?: string }[];
  }>();
  useEffect(() => {
    BrokerApi.dashboard().then(setD);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Broker mobile office"
        subtitle="Private client ledger and production control"
      />
      <View style={s.grid}>
        {d?.metrics.map((m) => (
          <Card key={m.label} style={s.metric}>
            <Text style={s.meta}>{m.label}</Text>
            <Text style={s.value}>{m.value}</Text>
          </Card>
        ))}
      </View>
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
    </Screen>
  );
}
const s = StyleSheet.create({
  grid: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  metric: { width: "48%", minHeight: 96 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
