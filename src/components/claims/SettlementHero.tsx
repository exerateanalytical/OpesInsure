import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { ShieldCheck } from "lucide-react-native";
import type { ClaimSettlement } from "@/api/client";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";


/**
 * Navy settlement-amount band (opesinsure_claim_settlement_dashboard) with the
 * approved / excess / net breakdown below it. Amounts come straight from the
 * settlement offer (GET /mobile/claims/{id}/settlement).
 */
export function SettlementHero({ settlement: x }: { settlement: ClaimSettlement }) {
  const { t, td } = useTranslation();
  const { xaf } = useFormatters();
  const paid = (x.payment_status ?? "").toUpperCase() === "PAID";
  return (
    <View style={s.wrap}>
      <View style={s.hero} accessible accessibilityLabel={`${t("settleNet")}: ${xaf(x.net_minor)}`}>
        <View style={s.flex}>
          <Text style={s.heroLabel}>{t("settleNet")}</Text>
          <Text style={s.heroAmount}>{xaf(x.net_minor)}</Text>
          <Text style={s.heroMeta}>
            {paid ? t("settlePaidStatus") : x.payment_status && x.payment_status !== "NOT_STARTED" ? td(`status_${x.payment_status}`, x.payment_status) : td(`settlementStatus_${x.status}`, x.status)}
          </Text>
        </View>
        <View pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          <View style={s.shield}>
            <ShieldCheck size={32} color={colors.gold500} />
          </View>
        </View>
      </View>
      <View style={s.split}>
        <Cell label={t("settleAssessed")} value={xaf(x.offered_minor)} />
        <View style={s.divider} />
        <Cell label={t("decisionExcess")} value={xaf(x.deductible_minor)} />
        <View style={s.divider} />
        <Cell label={t("settleNet")} value={xaf(x.net_minor)} strong />
      </View>
    </View>
  );
}

function Cell({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <View style={s.cell}>
      <Text style={s.cellLabel}>{label}</Text>
      <Text style={[s.cellValue, strong && s.cellStrong]}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x3 },
  flex: { flex: 1, gap: 4 },
  hero: { flexDirection: "row", alignItems: "center", gap: space.x3, backgroundColor: colors.navy900, borderRadius: radius.feature, padding: space.x4, overflow: "hidden" },
  heroLabel: { ...type.body, color: colors.white },
  heroAmount: { ...type.cardTitle, fontSize: 28, lineHeight: 34, color: colors.white },
  heroMeta: { ...type.meta, color: colors.blue100 },
  shield: { width: 64, height: 64, borderRadius: 32, backgroundColor: "rgba(255,255,255,0.1)", alignItems: "center", justifyContent: "center" },
  split: { flexDirection: "row", backgroundColor: colors.blue50, borderRadius: radius.card, paddingVertical: space.x3 },
  cell: { flex: 1, alignItems: "center", gap: 4, paddingHorizontal: space.x1 },
  divider: { width: 1, backgroundColor: colors.neutral200 },
  cellLabel: { ...type.meta, color: colors.neutral600, textAlign: "center" },
  cellValue: { ...type.label, color: colors.navy950, textAlign: "center" },
  cellStrong: { color: colors.blue700 },
});
