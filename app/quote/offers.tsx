import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Building2, Car, ChevronRight, Columns3, Info, Pencil, RefreshCcw, ShieldCheck } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import { Button, Card, ripple, Screen, TextField } from "@/components/ui";
import { FilterButton } from "@/components/SearchBar";
import { activeFilterCount, FiltersSheet, sortSection, type FilterSection, type FilterValues } from "@/components/filters";
import { InstitutionMark } from "@/components/InstitutionMark";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { OfferCard, useNow } from "@/components/offers/OfferCard";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import { useInsurance } from "@/store/insurance";
import {
  CoverLevel,
  filterOffers,
  insurerSummary,
  offerPaymentMethods,
  OfferSort,
  sortOffers,
  validityLeft,
} from "@/lib/purchase";
import { bestValueOfferId, carrierLogo, riskVehicleLabel } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const MAX_COMPARE = 3;
const toMinor = (v: string) => {
  const n = Number(v.replace(/[^\d]/g, ""));
  return v.trim() && Number.isFinite(n) ? n * 100 : null;
};
const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
const LEVELS: { key: CoverLevel; label: "ofLevelEssential" | "ofLevelStandard" | "ofLevelFull" }[] = [
  { key: "essential", label: "ofLevelEssential" },
  { key: "standard", label: "ofLevelStandard" },
  { key: "full", label: "ofLevelFull" },
];

/**
 * Offers from EVERY insurer that rated the quote. The server returns one
 * offer per active product of the line (all carriers); this screen lists
 * them all vertically with an "insurers that answered" summary. The 1.3.0
 * phone layout was a one-card-wide carousel, so users saw a single insurer.
 * "Best Value" is cover-per-franc from stated limits (same rule as renewals).
 */
export default function Offers() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const logoFor = useInsurerLogos();
  const product = useInsurance((s) => s.product);
  const offers = useInsurance((s) => s.offers);
  const quote = useInsurance((s) => s.quote);
  const busy = useInsurance((s) => s.busy);
  const error = useInsurance((s) => s.error);
  const choose = useInsurance((s) => s.selectOffer);
  const rerate = useInsurance((s) => s.rerateQuote);
  const now = useNow();
  const [sort, setSort] = useState<OfferSort>("price");
  const [providers, setProviders] = useState<string[]>([]);
  const [minPremium, setMinPremium] = useState("");
  const [maxPremium, setMaxPremium] = useState("");
  const [maxExcess, setMaxExcess] = useState("");
  const [levels, setLevels] = useState<CoverLevel[]>([]);
  const [methods, setMethods] = useState<string[]>([]);
  const [sheet, setSheet] = useState(false);
  const [compare, setCompare] = useState<string[]>([]);
  const [selecting, setSelecting] = useState<string | null>(null);
  const [selectError, setSelectError] = useState<unknown>(null);

  const insurers = useMemo(() => insurerSummary(offers), [offers]);
  const paymentMethods = useMemo(() => [...new Set(offers.flatMap(offerPaymentMethods))], [offers]);
  const visible = useMemo(
    () =>
      sortOffers(
        filterOffers(offers, {
          providers,
          minPremiumMinor: toMinor(minPremium),
          maxPremiumMinor: toMinor(maxPremium),
          maxExcessMinor: toMinor(maxExcess),
          coverLevels: levels,
          paymentMethods: methods,
        }),
        sort,
      ),
    [offers, providers, minPremium, maxPremium, maxExcess, levels, methods, sort],
  );
  const clearFilters = () => {
    setProviders([]);
    setMinPremium("");
    setMaxPremium("");
    setMaxExcess("");
    setLevels([]);
    setMethods([]);
  };
  // One filtering control: sort, insurer, cover level and payment option are sheet sections; amounts sit in the sheet footer.
  const sections = useMemo<FilterSection[]>(
    () => [
      sortSection(t, [
        { value: "price", label: t("ofSortPrice") },
        { value: "cover", label: t("ofSortCover") },
        { value: "insurer", label: t("ofSortInsurer") },
        { value: "excess", label: t("ofSortExcess") },
      ]),
      { key: "prov", title: t("ofInsurer"), options: insurers.map((i) => ({ value: i.carrierId, label: i.name })) },
      { key: "level", title: t("ofCoverLevel"), options: LEVELS.map((l) => ({ value: l.key, label: t(l.label) })) },
      { key: "method", title: t("ofPaymentOptions"), options: paymentMethods.map((m) => ({ value: m, label: m.replace(/_/g, " ") })) },
    ],
    [insurers, paymentMethods, t],
  );
  const sheetValue: FilterValues = { sort: [sort], prov: providers, level: levels, method: methods };
  const draftCount = (v: FilterValues) =>
    filterOffers(offers, {
      providers: v.prov ?? [],
      minPremiumMinor: toMinor(minPremium),
      maxPremiumMinor: toMinor(maxPremium),
      maxExcessMinor: toMinor(maxExcess),
      coverLevels: (v.level ?? []) as CoverLevel[],
      paymentMethods: v.method ?? [],
    }).length;
  const filterCount = activeFilterCount(sheetValue, sections) + [minPremium, maxPremium, maxExcess].filter(Boolean).length;
  const cheapest = useMemo(() => (offers.length ? Math.min(...offers.map((o) => o.total_minor)) : null), [offers]);
  const best = useMemo(() => bestValueOfferId(offers), [offers]);
  const quoteExpired =
    String(quote?.status ?? "").toUpperCase() === "EXPIRED" ||
    (offers.length > 0 && offers.every((o) => validityLeft(o.valid_until, now, f.language).expired));

  const select = async (id: string) => {
    const offer = offers.find((o) => o.id === id);
    if (!offer || !quote || selecting) return;
    setSelecting(id);
    setSelectError(null);
    try {
      await choose(offer);
      const proposal = useInsurance.getState().proposal;
      if (proposal) router.push({ pathname: "/proposals/[id]", params: { id: proposal.id } });
    } catch (e) {
      setSelectError(e);
    } finally {
      setSelecting(null);
    }
  };
  const toggleCompare = (id: string) =>
    setCompare((c) => (c.includes(id) ? c.filter((x) => x !== id) : c.length >= MAX_COMPARE ? c : [...c, id]));

  if (!quote)
    return (
      <Screen>
        <BrandHeader title={t("ofTitle")} />
        <QuoteSteps current={2} />
        <EmptyState title={t("ofNoQuote")} message={t("ofNoQuoteBody")} action={t("quotesTitle")} onPress={() => router.replace("/quotes")} />
      </Screen>
    );

  return (
    <Screen
      footer={
        compare.length > 0 ? (
          <CtaBar>
            <Button
              label={compare.length < 2 ? t("ofSelectTwo") : t("ofCompareN", { count: compare.length })}
              icon={Columns3}
              disabled={compare.length < 2}
              onPress={() => router.push({ pathname: "/quote/compare", params: { ids: compare.join(",") } })}
            />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("ofTitle")} subtitle={t("ofSubtitle", { count: offers.length })} />
      <QuoteSteps current={2} />
      <Card>
        <View style={st.quoteRow}>
          <TintedIcon icon={String(product ?? "").toLowerCase() === "motor" ? Car : ShieldCheck} tint="gold" size={56} />
          <View style={st.flex}>
            <Text style={st.quoteTitle}>{riskVehicleLabel(quote.risk_facts) ?? (product ? td(`qtProd_${product}`, product) : t("insuranceOffer"))}</Text>
            <Text style={ps.meta}>{[riskVehicleLabel(quote.risk_facts) && product ? td(`qtProd_${product}`, product) : null, quote.quote_number].filter(Boolean).join(" · ")}</Text>
          </View>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t("ofEditQuote")}
            onPress={() => router.push("/quote/risk")}
            android_ripple={ripple()}
            style={({ pressed }) => [st.editBtn, pressed && st.pressed]}
          >
            <Text style={st.editText}>{t("ofEditQuote")}</Text>
            <Pencil size={16} color={colors.blue600} />
          </Pressable>
        </View>
      </Card>
      <Banner icon={Info} tint="blue" body={t("ofNotice")} />
      {quoteExpired ? (
        <Card>
          <Text style={ps.title}>{t("ofExpiredTitle")}</Text>
          <Text style={ps.body}>{t("ofExpiredBody")}</Text>
          <Button label={t("ofRerate")} icon={RefreshCcw} loading={busy} onPress={() => void rerate(quote.id).catch(() => undefined)} />
        </Card>
      ) : null}
      {error && !selectError ? <ErrorCard error={{ message: error }} fallback={t("ofUpdateFailed")} onRetry={() => void rerate(quote.id).catch(() => undefined)} retryLabel={t("retry")} /> : null}
      {selectError ? <ErrorCard error={selectError} fallback={t("ofSelectFailed")} /> : null}

      {insurers.length > 0 ? (
        <Card>
          <SectionHeading icon={Building2} title={t("ofInsurersAnswered", { insurers: insurers.length, offers: offers.length })} />
          {insurers.map((i) => {
            const on = providers.includes(i.carrierId);
            return (
              <Pressable
                key={i.carrierId}
                accessibilityRole="button"
                accessibilityState={{ selected: on }}
                accessibilityLabel={i.name}
                accessibilityHint={t("ofInsurerFilterHint")}
                onPress={() => setProviders((p) => toggle(p, i.carrierId))}
                android_ripple={ripple()}
                style={({ pressed }) => [st.insurerRow, on && st.insurerRowOn, pressed && st.pressed]}
              >
                <InstitutionMark logoUrl={logoFor(i.carrierId, i.name, carrierLogo(i.cheapest))} initials={i.name.slice(0, 2).toUpperCase()} size={40} />
                <View style={st.flex}>
                  <Text style={st.insurerName}>{i.name}</Text>
                  <Text style={ps.meta}>
                    {t("ofInsurerOffers", { count: i.offers })} · <Text style={st.insurerPrice}>{t("ofFrom", { amount: f.xaf(i.cheapest.total_minor) })}</Text>
                  </Text>
                </View>
                <ChevronRight size={18} color={colors.neutral500} />
              </Pressable>
            );
          })}
          {insurers.length === 1 ? <Text style={ps.meta}>{t("ofSingleInsurer")}</Text> : null}
        </Card>
      ) : null}

      {offers.length > 1 ? (
        <>
          <View style={st.sortBlock}>
            <Text style={[st.sortLabel, st.flex]}>{`${t("ofSort")}: ${t(sort === "price" ? "ofSortPrice" : sort === "cover" ? "ofSortCover" : sort === "insurer" ? "ofSortInsurer" : "ofSortExcess")}`}</Text>
            <FilterButton onPress={() => setSheet(true)} label={t("ofFilters")} count={filterCount} />
          </View>
          {visible.length > 1 ? (
            <Button label={t("ofCompareAll", { count: visible.length })} icon={Columns3} variant="tertiary" onPress={() => router.push({ pathname: "/quote/compare", params: { ids: visible.map((o) => o.id).join(",") } })} />
          ) : null}
          <FiltersSheet
            visible={sheet}
            onClose={() => setSheet(false)}
            sections={sections}
            value={sheetValue}
            onApply={(v) => {
              setSort((v.sort?.[0] ?? "price") as OfferSort);
              setProviders(v.prov ?? []);
              setLevels((v.level ?? []) as CoverLevel[]);
              setMethods(v.method ?? []);
            }}
            count={draftCount}
            subtitle={t("ofFilterTitle")}
            footer={
              <Card>
                <View style={st.range}>
                  <View style={st.flex}>
                    <TextField label={t("ofMinPremium")} value={minPremium} onChangeText={setMinPremium} keyboardType="numeric" placeholder="0" />
                  </View>
                  <View style={st.flex}>
                    <TextField label={t("ofMaxPremium")} value={maxPremium} onChangeText={setMaxPremium} keyboardType="numeric" placeholder={t("ofNoLimit")} />
                  </View>
                </View>
                <TextField label={t("ofMaxExcess")} value={maxExcess} onChangeText={setMaxExcess} keyboardType="numeric" placeholder={t("ofNoLimit")} />
                <Button label={t("ofClearFilters")} variant="tertiary" onPress={clearFilters} />
              </Card>
            }
          />
        </>
      ) : null}

      {offers.length === 0 ? (
        <Card>
          <Text style={ps.title}>{t("ofNoneTitle")}</Text>
          <Text style={ps.meta}>{t("ofNoneBody")}</Text>
          <Button label={t("ofChangeDetails")} variant="secondary" onPress={() => router.replace("/quote/risk")} />
          <Button label={t("ofRetryRating")} variant="tertiary" loading={busy} onPress={() => void rerate(quote.id).catch(() => undefined)} />
        </Card>
      ) : visible.length === 0 ? (
        <EmptyState title={t("ofNoMatch")} message={t("ofNoMatchBody")} action={t("ofClearFilters")} onPress={clearFilters} />
      ) : (
        <>
          <Text style={ps.meta}>{t("ofShowing", { shown: visible.length, total: offers.length })}</Text>
          {visible.map((o) => (
            <OfferCard
              key={o.id}
              offer={o}
              all={offers}
              now={now}
              best={best === o.id}
              badge={o.total_minor === cheapest ? { label: t("ofLowest"), tone: "success" } : undefined}
              compareSelected={compare.includes(o.id)}
              onToggleCompare={offers.length > 1 ? () => toggleCompare(o.id) : undefined}
              selecting={selecting === o.id}
              disabled={!!selecting || busy || quoteExpired}
              onSelect={() => void select(o.id)}
            />
          ))}
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.9 },
  range: { flexDirection: "row", gap: space.x3 },
  quoteRow: { flexDirection: "row", alignItems: "center", gap: space.x3, flexWrap: "wrap" },
  quoteTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  editBtn: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 48, paddingHorizontal: space.x3, borderRadius: radius.control, backgroundColor: colors.blue50, overflow: "hidden" },
  editText: { ...type.label, color: colors.blue600 },
  sortBlock: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  sortLabel: { ...type.label, color: colors.navy950 },
  insurerRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    minHeight: 56,
    paddingHorizontal: space.x2,
    paddingVertical: space.x2,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: colors.neutral200,
    overflow: "hidden",
  },
  insurerRowOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  insurerName: { ...type.label, color: colors.navy950 },
  insurerPrice: { ...type.meta, fontFamily: "Inter_700Bold", color: colors.navy950, fontVariant: ["tabular-nums"] },
});
