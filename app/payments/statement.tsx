import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CalendarRange, Download, FileSpreadsheet, Scale } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, DetailRow, SectionHeading } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { SelectField } from "@/components/forms/SelectField";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { StatementsApi } from "@/api/customerFlows";
import { balanceSide, PERIOD_KEYS, statementPeriod, type PeriodKey } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Account statement (GET mobile/statements, REQ-PAY-015): premiums charged, payments,
 * refunds and adjustments for a period with the running balance; the same period
 * downloads as a PDF (format=pdf) in the in-app viewer.
 */
export default function Statement() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const [period, setPeriod] = useState<PeriodKey>("this_month");
  const range = statementPeriod(period);
  const q = useLoad(() => StatementsApi.get(range), [range.from, range.to]);

  return (
    <Screen>
      <BrandHeader title={t("stmTitle")} subtitle={t("stmSubtitle")} back right="help" />
      <Card style={s.card}>
        <SelectField
          label={t("stmPeriod")}
          value={period}
          onChange={(v) => setPeriod(v as PeriodKey)}
          options={PERIOD_KEYS.map((k) => ({ value: k, label: td(`stmPeriod_${k}`, k) }))}
        />
        <DetailRow icon={CalendarRange} label={t("stmRange")} value={`${f.date(range.from)} — ${f.date(range.to)}`} />
        <Button
          label={t("stmDownload")}
          icon={Download}
          variant="secondary"
          onPress={() => openDocumentUrl(StatementsApi.pdfUrl(range), t("stmTitle"), `statement-${range.from}-${range.to}.pdf`)}
        />
      </Card>
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("stmLoading")}>
        {(st) => {
          const side = balanceSide(st.closing_balance_minor, st.balance_meaning);
          return (
            <>
              <Card style={s.card}>
                <SectionHeading title={t("stmBalances")} icon={Scale} />
                <DetailRow label={t("stmOpening")} value={f.xaf(st.opening_balance_minor)} />
                <DetailRow label={t("stmClosing")} value={f.xaf(Math.abs(st.closing_balance_minor))} strong />
                <StatusChip label={t(side === "due" ? "stmDue" : side === "credit" ? "stmCredit" : "stmSettled")} tone={side === "due" ? "warning" : side === "credit" ? "info" : "success"} />
                <Text style={s.meta}>{st.statement_number}</Text>
              </Card>
              <Card style={s.card}>
                <SectionHeading title={t("stmLines")} icon={FileSpreadsheet} />
                {st.lines.length === 0 ? <Text style={s.meta}>{t("stmNoLines")}</Text> : null}
                {st.lines.map((l, i) => (
                  <View key={`${l.reference_type}-${l.reference_id}-${i}`} style={s.line}>
                    <View style={s.flex}>
                      <Text style={s.lineTitle}>{td(`stmType_${l.line_type}`, l.description || l.line_type)}</Text>
                      <Text style={s.meta}>{f.date(l.occurred_at)}</Text>
                    </View>
                    <View style={s.amounts}>
                      <Text style={[s.amount, l.amount_minor < 0 && s.negative]}>{l.amount_minor < 0 ? `− ${f.xaf(-l.amount_minor)}` : f.xaf(l.amount_minor)}</Text>
                      <Text style={s.meta}>{t("stmBalanceAfter", { amount: f.xaf(l.balance_minor) })}</Text>
                    </View>
                  </View>
                ))}
              </Card>
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  meta: { ...type.meta, color: colors.neutral600 },
  line: { flexDirection: "row", gap: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x2 },
  lineTitle: { ...type.label, color: colors.navy950 },
  amounts: { alignItems: "flex-end", gap: 2 },
  amount: { ...type.label, color: colors.navy950, fontVariant: ["tabular-nums"] },
  negative: { color: colors.successText },
});
