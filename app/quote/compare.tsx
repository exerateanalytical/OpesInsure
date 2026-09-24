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

/** Side-by-side table for 2–3 selected offers with normalized rows. */
export default function CompareOffers() {
  const { ids = "" } = useLocalSearchParams<{ ids?: string }>();
  const all = useInsurance((s) => s.offers);
  const f = useFormatters();
  const { width } = useWindowDimensions();
  const offers = useMemo(() => {
    const wanted = ids.split(",").filter(Boolean);
    return all.filter((o) => wanted.includes(o.id)).slice(0, 3);
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
  const colWidth = Math.max(130, Math.min(200, (width - 40 - labelWidth) / Math.max(offers.length, 1)));

  if (offers.length < 2)
    return (
      <Screen>
        <AppHeader title="Compare offers" back />
        <EmptyState title="Select two or three offers" message="Tick “Compare” on the offers you want to see side by side." action="Back to offers" onPress={() => router.back()} />
      </Screen>
    );

  return (
    <Screen>
      <AppHeader title="Compare offers" subtitle="Same rows for every insurer · best value highlighted" back />
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
                <Button label="Choose" variant="secondary" loading={choosing === o.id} disabled={!!choosing} onPress={() => void choose(o.id)} />
              </View>
            ))}
          </View>
        </View>
      </ScrollView>
      {error ? <ErrorCard error={error} fallback="This offer could not be selected." /> : null}
      <Text style={ps.meta}>“Not included” means the insurer’s offer does not list that cover. Figures come from each insurer’s rated offer; verify the policy wording before paying.</Text>
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
