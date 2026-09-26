import React, { useMemo, useState } from "react";
import { View } from "react-native";
import { Columns3, Info } from "lucide-react-native";
import { CompareTable, compareTableStyles } from "@/components/offers/CompareTable";
import { router, useLocalSearchParams } from "expo-router";
import { Banner, BrandHeader, SectionHeading } from "@/components/design";
import { Button, Card, Screen } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, QuoteSteps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { compareRows, providerName } from "@/lib/purchase";
import { carrierLogo } from "@/lib/renewal";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
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
  const logoFor = useInsurerLogos();
  const marks = offers.map((o) => {
    const name = providerName(o, f.language);
    return { logoUrl: logoFor(o.carrier?.id ?? o.carrier_id, name, carrierLogo(o)), initials: name.slice(0, 2).toUpperCase() };
  });
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
        <BrandHeader title={t("compare")} />
        <QuoteSteps current={2} />
        <EmptyState title={t("qtSelectTwoThree")} message={t("qtTickCompare")} action={t("qtBackToOffers")} onPress={() => router.back()} />
      </Screen>
    );

  return (
    <Screen>
      <BrandHeader title={t("compare")} subtitle={t("qtCompareSubtitle", { count: offers.length })} />
      <QuoteSteps current={2} />
      <Card>
        <SectionHeading icon={Columns3} title={t("compare")} />
        <CompareTable
          rows={rows}
          columns={offers.length}
          money={f.xaf}
          marks={marks}
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
      </Card>
      {error ? <ErrorCard error={error} fallback={t("ofSelectFailed")} /> : null}
      <Banner icon={Info} tint="blue" body={t("qtCompareNote")} />
    </Screen>
  );
}
