import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { CarFront, Columns3, Info, Pencil, ShieldCheck } from "lucide-react-native";
import { CompareTable, compareTableStyles } from "@/components/offers/CompareTable";
import { OfferCard, useNow } from "@/components/offers/OfferCard";
import { router, useLocalSearchParams } from "expo-router";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { Button, Card, ripple, Screen } from "@/components/ui";
import { SortFilter } from "@/components/filters";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { compareRows, OfferSort, providerName, sortOffers } from "@/lib/purchase";
import { bestValueOfferId, carrierLogo, riskVehicleLabel } from "@/lib/renewal";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Compare offers (design opesinsure_compare_offers_screen): quote summary
 * with "Edit quote", sort chips, one full offer card per selected insurer
 * (Best Value in gold), then the normalized side-by-side table (2–3 ticked,
 * or every insurer via t("qtCompareAll")) which scrolls horizontally.
 */
export default function CompareOffers() {
  const { t, td } = useTranslation();
  const { ids = "" } = useLocalSearchParams<{ ids?: string }>();
  const all = useInsurance((s) => s.offers);
  const quote = useInsurance((s) => s.quote);
  const product = useInsurance((s) => s.product);
  const f = useFormatters();
  const now = useNow();
  const [sort, setSort] = useState<OfferSort>("price");
  const offers = useMemo(() => {
    const wanted = ids.split(",").filter(Boolean);
    return all.filter((o) => wanted.includes(o.id));
  }, [all, ids]);
  const sorted = useMemo(() => sortOffers(offers, sort), [offers, sort]);
  const best = useMemo(() => bestValueOfferId(offers), [offers]);
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

  const vehicle = riskVehicleLabel(quote?.risk_facts);
  const productLabel = product ? td(`qtProd_${product}`, product) : null;

  return (
    <Screen>
      <BrandHeader title={t("cmpPageTitle")} subtitle={t("cmpPageSubtitle")} />
      <QuoteSteps current={2} />
      {quote ? (
        <Card>
          <View style={st.quoteRow}>
            <TintedIcon icon={String(product ?? "").toLowerCase() === "motor" ? CarFront : ShieldCheck} tint="gold" size={56} />
            <View style={st.quoteText}>
              <Text style={st.quoteTitle}>{vehicle ?? productLabel ?? t("insuranceOffer")}</Text>
              <Text style={ps.meta}>{[vehicle && productLabel ? productLabel : null, quote.quote_number].filter(Boolean).join(" · ")}</Text>
            </View>
            <Pressable accessibilityRole="button" accessibilityLabel={t("ofEditQuote")} onPress={() => router.push("/quote/risk")} android_ripple={ripple()} style={({ pressed }) => [st.editBtn, pressed && st.pressed]}>
              <Text style={st.editText}>{t("ofEditQuote")}</Text>
              <Pencil size={16} color={colors.blue600} />
            </Pressable>
          </View>
        </Card>
      ) : null}
      <SortFilter
        value={sort}
        onChange={setSort}
        count={offers.length}
        options={[
          { value: "price", label: t("ofSortPrice") },
          { value: "cover", label: t("ofSortCover") },
          { value: "insurer", label: t("ofSortInsurer") },
          { value: "excess", label: t("ofSortExcess") },
        ]}
      />
      {error ? <ErrorCard error={error} fallback={t("ofSelectFailed")} /> : null}
      {sorted.map((o) => (
        <OfferCard key={o.id} offer={o} all={offers} best={best === o.id} onSelect={() => void choose(o.id)} selecting={choosing === o.id} disabled={!!choosing} now={now} />
      ))}
      <Card>
        <SectionHeading icon={Columns3} title={t("compare")} />
        <Text style={ps.meta}>{t("qtCompareSubtitle", { count: offers.length })}</Text>
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
      <Banner icon={Info} tint="blue" body={t("qtCompareNote")} />
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  quoteRow: { flexDirection: "row", alignItems: "center", gap: space.x3, flexWrap: "wrap" },
  quoteText: { flexGrow: 1, flexShrink: 1, flexBasis: 150 },
  quoteTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  editBtn: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 44, paddingHorizontal: space.x3, borderRadius: radius.control, backgroundColor: colors.blue50, overflow: "hidden" },
  editText: { ...type.label, color: colors.blue600 },
});
