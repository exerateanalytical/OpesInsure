import React, { useMemo, useState } from "react";
import { ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Screen } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { compareRows } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Side-by-side table for the selected offers (2–3 ticked, or every
 * insurer via t("qtCompareAll")) with normalized rows; scrolls horizontally. */
export default function CompareOffers() {
  const { t } = useTranslation();
  const { ids = "" } = useLocalSearchParams<{ ids?: string }>();
  const all = useInsurance((s) => s.offers);
  const f = useFormatters();
  const { width } = useWindowDimensions();
  const offers = useMemo(() => {
    const wanted = ids.split(",").filter(Boolean);
    return all.filter((o) => wanted.includes(o.id));
  }, [all, ids]);
  const rows = useMemo(() => compareRows(offers, f.language), [offers, f.language]);
  const selectOffer = useInsurance((s) => s.selectOffer);
  const [choosing, setChoosing] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const choose = async (id: string) => {
    const offer = offers.find((o) => o.id === id);
    if (!offer || choosing) return;
    setChoosing(id);
    setError(null);
    try {
      await selectOffer(offer);
      const proposal = useInsurance.getState().proposal;
      if (proposal) router.replace({ pathname: "/proposals/[id]", params: { id: proposal.id } });
    } catch (e) {
      setError(e);
    } finally {
      setChoosing(null);
    }
  };
  const labelWidth = 120;
  // Columns never shrink below a readable 140dp; with many insurers the
  // table scrolls horizontally instead of squeezing text.
  const colWidth = Math.max(140, Math.min(200, (width - 40 - labelWidth) / Math.max(offers.length, 1)));

  if (offers.length < 2)
    return (
      <Screen>
        <AppHeader title={t("compare")} back />
        <EmptyState title={t("qtSelectTwoThree")} message={t("qtTickCompare")} action={t("qtBackToOffers")} onPress={() => router.back()} />
      </Screen>
    );

  return (
    <Screen>
      <AppHeader title={t("compare")} subtitle={t("qtCompareSubtitle", { count: offers.length })} back />
      <ScrollView horizontal showsHorizontalScrollIndicator>
        <View style={st.table}>
          {rows.map((row, r) => (
            <View key={row.key} style={[st.row, r % 2 === 1 && st.zebra, r === 0 && st.head]}>
              <Text style={[st.label, { width: labelWidth }]}>{row.label}</Text>
              {row.cells.map((cell, i) => (
                <View key={`${row.key}-${i}`} style={[st.cell, { width: colWidth }, cell.best && st.best]}>
                  {cell.minor !== undefined && cell.minor !== null ? <Text style={[st.value, cell.best && st.bestText]}>{f.xaf(cell.minor)}</Text> : null}
                  {cell.text ? <Text style={cell.minor !== undefined && cell.minor !== null ? ps.meta : st.value}>{cell.text}</Text> : null}
                </View>
              ))}
            </View>
          ))}
          <View style={st.row}>
            <View style={{ width: labelWidth }} />
            {offers.map((o) => (
              <View key={o.id} style={[st.cell, { width: colWidth }]}>
                <Button label={t("qtChoose")} variant="secondary" loading={choosing === o.id} disabled={!!choosing} onPress={() => void choose(o.id)} />
              </View>
            ))}
          </View>
        </View>
      </ScrollView>
      {error ? <ErrorCard error={error} fallback={t("ofSelectFailed")} /> : null}
      <Text style={ps.meta}>{t("qtCompareNote")}</Text>
    </Screen>
  );
}

const st = StyleSheet.create({
  table: { borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, overflow: "hidden", backgroundColor: colors.white },
  row: { flexDirection: "row", alignItems: "stretch", borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  zebra: { backgroundColor: colors.neutral50 },
  head: { backgroundColor: colors.blue50 },
  label: { ...type.label, color: colors.navy950, padding: space.x2 },
  cell: { padding: space.x2, gap: 2, borderLeftWidth: 1, borderLeftColor: colors.neutral100 },
  value: { ...type.meta, color: colors.navy950 },
  best: { backgroundColor: colors.successSoft },
  bestText: { color: colors.successText, fontFamily: "Inter_700Bold" },
});
