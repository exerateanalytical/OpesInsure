import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { CalendarClock, CreditCard } from "lucide-react-native";
import { Button, Card, StatusChip } from "@/components/ui";
import { SectionHeading } from "@/components/design";
import { InstalmentsApi } from "@/api/customerFlows";
import { instalmentTone } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Premium instalment schedule on the policy detail (GET mobile/policies/{id}/instalments):
 * number, due date, amount, status, paid date, with Pay on the lines the server marks
 * payable. Renders nothing for a single-payment policy (no schedule) or when the
 * schedule cannot be loaded (it never blocks the rest of the policy page).
 */
export function InstalmentSection({ policyId }: { policyId: string }) {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => InstalmentsApi.schedule(policyId), [policyId]);
  const reload = q.reload;
  useFocusEffect(
    React.useCallback(() => {
      void reload();
    }, [reload]),
  );
  const rows = q.data?.data ?? [];
  if (!rows.length) return null;
  return (
    <Card style={s.card}>
      <SectionHeading title={t("instTitle")} icon={CalendarClock} />
      {q.data && q.data.meta.outstanding_minor > 0 ? <Text style={s.meta}>{t("instOutstanding", { amount: f.xaf(q.data.meta.outstanding_minor) })}</Text> : null}
      {rows.map((i) => (
        <View key={i.id} style={s.row} accessible accessibilityLabel={`${t("instNumber", { number: i.number })}, ${f.date(i.due_date)}, ${f.xaf(i.amount_minor)}, ${td(`instStatus_${i.status}`, i.status)}`}>
          <View style={s.flex}>
            <Text style={s.title}>{t("instNumber", { number: i.number })}</Text>
            <Text style={s.meta}>
              {i.paid_at ? t("instPaidOn", { date: f.date(i.paid_at) }) : t("instDueOn", { date: f.date(i.due_date) })} · {f.xaf(i.amount_minor)}
            </Text>
            <StatusChip label={i.payment_in_progress ? t("instPaying") : td(`instStatus_${i.status}`, i.status)} tone={instalmentTone(i)} />
          </View>
          {i.payable ? (
            <Button
              label={t("instPay")}
              icon={CreditCard}
              size="small"
              variant={i.overdue ? "primary" : "secondary"}
              onPress={() => router.push({ pathname: "/policy/[id]/instalment-pay", params: { id: policyId, instalmentId: i.id } })}
            />
          ) : null}
        </View>
      ))}
    </Card>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x3 },
  flex: { flex: 1, gap: 4, alignItems: "flex-start" },
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
