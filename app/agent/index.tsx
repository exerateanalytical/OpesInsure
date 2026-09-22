import React, { useEffect, useState } from "react";
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
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi, AgentSale } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";
export default function AgentHome() {
  const [d, setD] = useState<{
    metrics: { label: string; value: string; tone?: string }[];
    recent_sales: AgentSale[];
  }>();
  useEffect(() => {
    AgentApi.dashboard().then(setD);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Agent field desk"
        subtitle="Protected clients, assisted sales and earnings"
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
    </Screen>
  );
}
const s = StyleSheet.create({
  grid: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  metric: { width: "48%", minHeight: 96 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
