import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierFinanceApi, fcfa } from "@/api/extra";
import { colors, space, type } from "@/theme/tokens";

const day = (v?: string | null) => (v ? new Date(v).toLocaleDateString() : "");

export default function CarrierSettlementDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierFinanceApi.settlement(id), [id]);
  return (
    <Screen>
      <AppHeader title="Settlement" subtitle={q.data?.settlement_number ?? undefined} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false}>
        {(s) => (
          <>
            <Card feature>
              <StatusChip label={s.status.replaceAll("_", " ")} tone="info" />
              <Text style={styles.amount}>{fcfa(s.net_amount_minor)}</Text>
              <Text style={styles.body}>
                Period {day(s.period_start)} – {day(s.period_end)}
              </Text>
              {s.submitted_at ? (
                <Text style={styles.body}>Submitted {day(s.submitted_at)}</Text>
              ) : null}
              {s.paid_at ? (
                <Text style={styles.body}>
                  Paid {day(s.paid_at)}
                  {s.bank_reference ? ` · ref ${s.bank_reference}` : ""}
                </Text>
              ) : null}
            </Card>
            <SectionTitle title={`Items (${s.items?.length ?? 0})`} />
            {s.items?.length ? (
              <OperationsList
                icon={FileCheck2}
                rows={s.items.map((i) => ({
                  id: i.id,
                  title: `Net due ${fcfa(i.net_due_minor)}`,
                  subtitle: `Gross ${fcfa(i.gross_premium_minor)} · commission ${fcfa(i.commission_minor)}`,
                  status: i.status,
                }))}
              />
            ) : (
              <View style={styles.empty}>
                <Text style={styles.body}>This batch has no items.</Text>
              </View>
            )}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  amount: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  empty: { paddingVertical: space.x4 },
});
