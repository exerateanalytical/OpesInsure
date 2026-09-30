import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierFinanceApi, fcfa } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const day = (v?: string | null) => (v ? formatDisplayDate(v) : "");

export default function CarrierSettlementDetail() {
  return (
    <CarrierGate module="settlements">
      <CarrierSettlementDetailBody />
    </CarrierGate>
  );
}

function CarrierSettlementDetailBody() {
  const { t, td } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierFinanceApi.settlement(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("trackSettlement")} subtitle={q.data?.settlement_number ?? undefined} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false}>
        {(s) => (
          <>
            <Card feature>
              <StatusChip label={td(`settleStatus_${s.status}`, s.status)} tone="info" />
              <Text style={styles.amount}>{fcfa(s.net_amount_minor)}</Text>
              <Text style={styles.body}>
                {t("caSettlePeriod", { from: day(s.period_start), to: day(s.period_end) })}
              </Text>
              {s.submitted_at ? (
                <Text style={styles.body}>{t("caSettleSubmitted", { date: day(s.submitted_at) })}</Text>
              ) : null}
              {s.paid_at ? (
                <Text style={styles.body}>
                  {t("caSettlePaid", { date: day(s.paid_at) })}
                  {s.bank_reference ? ` · ${t("caSettleBankRef", { reference: s.bank_reference })}` : ""}
                </Text>
              ) : null}
            </Card>
            <SectionTitle title={t("cdLineItems", { count: s.items?.length ?? 0 })} />
            {s.items?.length ? (
              <OperationsList
                icon={FileCheck2}
                rows={s.items.map((i) => ({
                  id: i.id,
                  title: t("caNetDue", { amount: fcfa(i.net_due_minor) }),
                  subtitle: t("caSettleItemBreakdown", { gross: fcfa(i.gross_premium_minor), commission: fcfa(i.commission_minor) }),
                  status: td(`settleStatus_${i.status}`, i.status),
                }))}
              />
            ) : (
              <View style={styles.empty}>
                <Text style={styles.body}>{t("caBatchEmpty")}</Text>
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
