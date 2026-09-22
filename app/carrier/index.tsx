import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import {
  ClipboardCheck,
  FileCheck2,
  HandCoins,
  ShieldAlert,
} from "lucide-react-native";
import { StyleSheet, Text, View } from "react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { CarrierApi } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";
export default function CarrierHome() {
  const [d, setD] = useState<{
    metrics: { label: string; value: string; tone?: string }[];
  }>();
  useEffect(() => {
    CarrierApi.dashboard().then(setD);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Carrier operations"
        subtitle="Underwriting, issuance, claims and settlement"
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
          icon={ClipboardCheck}
          title="Underwriting referrals"
          subtitle="Review risks outside automatic authority"
          onPress={() => router.push("/carrier/referrals")}
        />
        <FlowRow
          icon={FileCheck2}
          title="Issuance queue"
          subtitle="Paid proposals awaiting policy issuance"
          onPress={() => router.push("/carrier/issuance")}
        />
        <FlowRow
          icon={ShieldAlert}
          title="Claims queue"
          subtitle="Evidence and decision work"
          onPress={() => router.push("/carrier/claims")}
        />
        <FlowRow
          icon={HandCoins}
          title="Settlements"
          subtitle="Reconciled premium statements"
          onPress={() => router.push("/carrier/settlements")}
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
