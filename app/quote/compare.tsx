import React, { useMemo, useState } from "react";
import { Text, View } from "react-native";
import { CompareTable, compareTableStyles } from "@/components/offers/CompareTable";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Screen } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { compareRows } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";

/** Side-by-side table for the selected offers (2–3 ticked, or every
 * insurer via t("qtCompareAll")) with normalized rows; scrolls horizontally. */
export default function CompareOffers() {
  const { t } = useTranslation();
  const { ids = "" } = useLocalSearchParams<{ ids?: string }>();
  const all = useInsurance((s) => s.offers);
  const f = useFormatters();
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
      <CompareTable
        rows={rows}
        columns={offers.length}
        money={f.xaf}
        footer={(colWidth, labelWidth) => (
          <View style={compareTableStyles.row}>
            <View style={{ width: labelWidth }} />
            {offers.map((o) => (
              <View key={o.id} style={[compareTableStyles.cell, { width: colWidth }]}>
                <Button label={t("qtChoose")} variant="secondary" loading={choosing === o.id} disabled={!!choosing} onPress={() => void choose(o.id)} />
              </View>
            ))}
          </View>
        )}
      />
      {error ? <ErrorCard error={error} fallback={t("ofSelectFailed")} /> : null}
      <Text style={ps.meta}>{t("qtCompareNote")}</Text>
    </Screen>
  );
}

