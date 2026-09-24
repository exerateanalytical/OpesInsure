import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import {
  ClipboardCheck,
  FileCheck2,
  FileSpreadsheet,
  HandCoins,
  ShieldAlert,
} from "lucide-react-native";
import { StyleSheet, Text, View } from "react-native";
import { Card } from "@/components/ui";
import { PortalHeader, PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { useColumns } from "@/components/responsive";
import { FlowRow } from "@/components/FlowPrimitives";
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
        <FlowRow
          icon={FileSpreadsheet}
          title="Bordereaux"
          subtitle="Premium and commission bordereaux from brokers"
          onPress={() => router.push("/carrier/bordereaux")}
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
