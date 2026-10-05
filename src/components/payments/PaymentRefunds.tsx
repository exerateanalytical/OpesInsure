import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Undo2 } from "lucide-react-native";
import { Card, StatusChip } from "@/components/ui";
import { SectionHeading } from "@/components/design";
import { RefundsApi } from "@/api/customerFlows";
import { refundTone } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Refunds of this payment (GET mobile/payments/{id}/refunds, REQ-PAY-009): number,
 * amount, status and the dates of each step. Renders nothing when there is none or
 * the list cannot be loaded (never blocks the payment page).
 */
export function PaymentRefunds({ paymentId }: { paymentId: string }) {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => RefundsApi.forPayment(paymentId), [paymentId]);
  const rows = q.data ?? [];
  if (!rows.length) return null;
  return (
    <Card style={s.card}>
      <SectionHeading title={t("rfListTitle")} icon={Undo2} />
      {rows.map((r) => (
        <View key={r.id} style={s.row}>
          <View style={s.head}>
            <Text style={s.title}>{r.refund_number}</Text>
            <StatusChip label={td(`rfStatus_${r.status}`, r.status)} tone={refundTone(r.status)} />
          </View>
          <Text style={s.amount}>{f.xaf(r.amount_minor)}</Text>
          <Text style={s.meta}>{t("rfRequested", { date: f.date(r.requested_at) })}</Text>
          {r.approved_at ? <Text style={s.meta}>{t("rfApproved", { date: f.date(r.approved_at) })}</Text> : null}
          {r.paid_at ? <Text style={s.meta}>{t("rfPaid", { date: f.date(r.paid_at) })}</Text> : null}
          {r.rejected_at ? <Text style={s.meta}>{t("rfRejected", { date: f.date(r.rejected_at) })}{r.rejection_reason ? ` · ${r.rejection_reason}` : ""}</Text> : null}
        </View>
      ))}
    </Card>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { gap: 2, borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x2 },
  head: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.label, color: colors.navy950 },
  amount: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
