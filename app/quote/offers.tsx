import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import { Building2, CheckCircle2, Columns3, Info, RefreshCcw, XCircle } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { FilterButton } from "@/components/SearchBar";
import { activeFilterCount, FiltersSheet, sortSection, type FilterSection, type FilterValues } from "@/components/filters";
import { initialsOf } from "@/components/filters/FilteredList";
import { InstitutionMark } from "@/components/InstitutionMark";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { OfferCard, useNow } from "@/components/offers/OfferCard";
import { QuoteSummaryCard } from "@/components/offers/QuoteSummaryCard";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import { useInsurance } from "@/store/insurance";
import { useChooseOffer, useEditQuote, useOpenQuote } from "@/hooks/useQuoteFlow";
import { CoverLevel, filterOffers, insurerSummary, liveOffers, offerBlock, offerPaymentMethods, OfferSort, paymentMethodLabel, providerName, sortOffers } from "@/lib/purchase";
import { amountFilter, amountFilterCount, amountInvalid, compareAllIds, MAX_COMPARE, offerChoice, pruneCompareIds, toggleCompareId } from "@/lib/offerChoice";
import { quoteOutcome } from "@/lib/quoteWorkflow";
import { bestValueOfferId, carrierLogo } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { focusedCarrierIds, focusFromParams } from "@/lib/quoteFocus";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const LEVELS: { key: CoverLevel; label: "ofLevelEssential" | "ofLevelStandard" | "ofLevelFull" }[] = [
  { key: "essential", label: "ofLevelEssential" },
  { key: "standard", label: "ofLevelStandard" },
  { key: "full", label: "ofLevelFull" },
];
const SORT_LABEL = { price: "ofSortPrice", cover: "ofSortCover", insurer: "ofSortInsurer", excess: "ofSortExcess" } as const;

/**
 * Offers from EVERY insurer that rated the quote (`quoteId` param; reloaded after a restart or a
 * deep link). Listed vertically with an informational "insurers that answered" summary; search is
 * not needed here, so sort, insurer, cover level, payment option and the amount range all live in
 * the one filter sheet. Only offers that can still be chosen are ticked for comparison, highlighted
 * ("Lowest total", "Best Value") or quoted "from"; once an offer is accepted its card opens the
 * application and the others are blocked, so no second application is created.
 */
export default function Offers() {
  const { t } = useTranslation();
  const f = useFormatters();
  const logoFor = useInsurerLogos();
  // carriers/from: the insurer (or the broker's insurers) chosen on a profile; the offers open filtered to it.
  const { quoteId, carriers, from } = useLocalSearchParams<{ quoteId?: string; carriers?: string; from?: string }>();
  const focus = useMemo(() => focusFromParams(carriers, from), [carriers, from]);
  const open = useOpenQuote(quoteId);
  const { quote, offers } = open;
  const product = useInsurance((s) => s.product);
  const busy = useInsurance((s) => s.busy);
  const clearStoreError = useInsurance((s) => s.clearError);
  const rerate = useInsurance((s) => s.rerateQuote);
  const now = useNow();
  const editQuote = useEditQuote(quote?.id);
  const pick = useChooseOffer(quote?.id, offers);
  const [filters, setFilters] = useState<FilterValues>({ sort: ["price"] });
  const [sheet, setSheet] = useState(false);
  const [compare, setCompare] = useState<string[]>([]);
  const [compareNotice, setCompareNotice] = useState(false);
  const [rateError, setRateError] = useState<unknown>(null);

  // Errors belong to the action that raised them: nothing from another screen shows up here.
  useFocusEffect(
    useCallback(() => {
      clearStoreError();
    }, [clearStoreError]),
  );

  const sort = (filters.sort?.[0] ?? "price") as OfferSort;
  const live = useMemo(() => liveOffers(offers, now), [offers, now]);
  const insurers = useMemo(() => insurerSummary(offers, f.language, now), [offers, f.language, now]);
  const paymentMethods = useMemo(() => [...new Set(offers.flatMap(offerPaymentMethods))], [offers]);
  const filterOf = useCallback(
    (v: FilterValues) =>
      filterOffers(offers, { providers: v.prov ?? [], coverLevels: (v.level ?? []) as CoverLevel[], paymentMethods: v.method ?? [], ...amountFilter(v) }),
    [offers],
  );
  const visible = useMemo(() => sortOffers(filterOf(filters), sort), [filterOf, filters, sort]);

  // One filtering control: sort, insurer, cover level and payment option are sheet sections; the amounts sit in its footer.
  const sections = useMemo<FilterSection[]>(
    () => [
      sortSection(t, (Object.keys(SORT_LABEL) as OfferSort[]).map((value) => ({ value, label: t(SORT_LABEL[value]) }))),
      { key: "prov", title: t("ofInsurer"), options: insurers.map((i) => ({ value: i.carrierId, label: i.name, logoUrl: logoFor(i.carrierId, i.name, carrierLogo(i.any)), initials: initialsOf(i.name) })) },
      { key: "level", title: t("ofCoverLevel"), options: LEVELS.map((l) => ({ value: l.key, label: t(l.label) })) },
      { key: "method", title: t("ofPaymentOptions"), options: paymentMethods.map((m) => ({ value: m, label: paymentMethodLabel(m, f.language) })) },
    ],
    [insurers, paymentMethods, t, f.language, logoFor],
  );
  const filterCount = activeFilterCount(filters, sections) + amountFilterCount(filters);
  const clearFilters = () => setFilters({ sort: [sort] });

  // Profile focus: applied once as the insurer filter (the filter sheet can still change it); "See all" clears it.
  const focusIds = useMemo(() => focusedCarrierIds(focus, insurers.map((i) => i.carrierId)), [focus, insurers]);
  const focusApplied = useRef(false);
  useEffect(() => {
    if (focusApplied.current || !focusIds.length) return;
    focusApplied.current = true;
    setFilters((v) => ({ ...v, prov: focusIds }));
  }, [focusIds]);
  const focused = !!focusIds.length && (filters.prov ?? []).length === focusIds.length && focusIds.every((id) => filters.prov?.includes(id));
  const focusMissed = !!focus && insurers.length > 0 && !focusIds.length;
  const seeAllInsurers = () => setFilters((v) => ({ ...v, prov: [] }));

  // Ticks follow what is on screen: a re-rate, a filter or an expiry removes offers that are gone or no longer choosable.
  useEffect(() => {
    setCompare((c) => pruneCompareIds(c, visible, now));
  }, [visible, now]);

  const cheapest = live.length ? Math.min(...live.map((o) => o.total_minor)) : null;
  const best = useMemo(() => bestValueOfferId(live), [live]);
  const outcome = quote ? quoteOutcome(quote, now) : null;
  const expired = outcome === "EXPIRED" || (offers.length > 0 && offers.every((o) => offerBlock(o, now) === "expired"));
  const ended = outcome === "DECLINED" || outcome === "CANCELLED";
  const acceptedOffer = pick.acceptedId ? offers.find((o) => o.id === pick.acceptedId) ?? null : null;
  const canCompare = !pick.acceptedId && live.length > 1;
  const compareAll = compareAllIds(visible, now);

  const doRerate = async () => {
    if (!quote) return;
    setRateError(null);
    try {
      await rerate(quote.id);
    } catch (e) {
      setRateError(e);
    }
  };
  const toggleCompare = (id: string) => {
    const next = toggleCompareId(compare, id);
    setCompareNotice(next.limited);
    setCompare(next.ids);
  };
  const openCompare = (ids: string[]) => quote && router.push({ pathname: "/quote/compare", params: { quoteId: quote.id, ids: ids.join(",") } });

  if (!quote)
    return (
      <Screen>
        <BrandHeader title={t("ofTitle")} />
        <QuoteSteps current={2} />
        {open.loading ? (
          <LoadingState label={t("qwLoading")} />
        ) : open.error ? (
          <ErrorCard error={open.error} fallback={t("qwLoadFailed")} onRetry={() => void open.reload()} />
        ) : (
          <EmptyState title={t("ofNoQuote")} message={t("ofNoQuoteBody")} action={t("quotesTitle")} onPress={() => router.replace("/quotes")} />
        )}
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
              onPress={() => openCompare(compare)}
            />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("ofTitle")} subtitle={t("ofSubtitle", { count: live.length })} />
      <QuoteSteps current={2} />
      <QuoteSummaryCard quote={quote} product={product} onEdit={pick.acceptedId || (outcome && outcome !== "EXPIRED") ? undefined : editQuote} />
      <Banner icon={Info} tint="blue" body={t("ofNotice")} />
      {acceptedOffer ? (
        <Card>
          <SectionHeading icon={CheckCircle2} title={t("ofAcceptedTitle")} />
          <Text style={ps.body}>{t("ofAcceptedBody", { insurer: providerName(acceptedOffer, f.language) })}</Text>
          <Button label={t("coOpenApplication")} loading={pick.opening} onPress={() => void pick.openApplication()} />
        </Card>
      ) : ended ? (
        <Card>
          <SectionHeading icon={XCircle} title={t("ofQuoteEndedTitle")} />
          <Text style={ps.body}>{t("ofQuoteEndedBody")}</Text>
          <Button label={t("qwNewQuote")} variant="secondary" onPress={() => router.replace("/quote/product")} />
        </Card>
      ) : expired ? (
        <Card>
          <Text style={ps.title}>{t("ofExpiredTitle")}</Text>
          <Text style={ps.body}>{t("ofExpiredBody")}</Text>
          <Button label={t("ofRerate")} icon={RefreshCcw} loading={busy} disabled={busy} onPress={() => void doRerate()} />
        </Card>
      ) : null}
      {rateError ? <ErrorCard error={rateError} fallback={t("ofUpdateFailed")} onRetry={() => void doRerate()} retryLabel={t("retry")} /> : null}
      {pick.error ? <ErrorCard error={pick.error} fallback={t("ofSelectFailed")} /> : null}

      {insurers.length > 0 ? (
        <Card>
          <SectionHeading icon={Building2} title={t("ofInsurersAnswered", { insurers: insurers.length, offers: offers.length })} />
          {/* Informational: filtering by insurer is done in the filter sheet. */}
          {insurers.map((i) => (
            <View key={i.carrierId} style={st.insurerRow} accessible accessibilityLabel={[i.name, t("ofInsurerOffers", { count: i.offers }), i.cheapest ? t("ofFrom", { amount: f.xaf(i.cheapest.total_minor) }) : t("ofInsurerNoLive")].join(", ")}>
              <InstitutionMark logoUrl={logoFor(i.carrierId, i.name, carrierLogo(i.any))} initials={initialsOf(i.name)} size={40} />
              <View style={st.flex}>
                <Text style={st.insurerName}>{i.name}</Text>
                <Text style={ps.meta}>
                  {t("ofInsurerOffers", { count: i.offers })} ·{" "}
                  {i.cheapest ? <Text style={st.insurerPrice}>{t("ofFrom", { amount: f.xaf(i.cheapest.total_minor) })}</Text> : <Text style={st.muted}>{t("ofInsurerNoLive")}</Text>}
                </Text>
              </View>
            </View>
          ))}
          {insurers.length === 1 ? <Text style={ps.meta}>{t("ofSingleInsurer")}</Text> : null}
        </Card>
      ) : null}

      {focused && focus ? (
        <View style={st.focusRow}>
          <StatusChip label={t("ofOffersFrom", { name: focus.name || insurers.find((i) => i.carrierId === focusIds[0])?.name || "" })} tone="info" />
          <Button label={t("ofSeeAllInsurers")} variant="tertiary" size="small" onPress={seeAllInsurers} />
        </View>
      ) : focusMissed && focus ? (
        <Text style={ps.meta}>{t("ofFocusNoOffer", { name: focus.name })}</Text>
      ) : null}

      {offers.length > 1 ? (
        <>
          <View style={st.sortBlock}>
            <Text style={[st.sortLabel, st.flex]}>{`${t("ofSort")}: ${t(SORT_LABEL[sort])}`}</Text>
            <FilterButton onPress={() => setSheet(true)} label={t("ofFilters")} count={filterCount} />
          </View>
          {canCompare && compareAll.length > 1 ? (
            <Button
              label={visible.filter((o) => !offerBlock(o, now)).length > MAX_COMPARE ? t("ofCompareBest", { count: compareAll.length }) : t("ofCompareAll", { count: compareAll.length })}
              icon={Columns3}
              variant="tertiary"
              onPress={() => openCompare(compareAll)}
            />
          ) : null}
          <FiltersSheet
            visible={sheet}
            onClose={() => setSheet(false)}
            sections={sections}
            value={filters}
            onApply={(v) => setFilters({ ...v, sort: v.sort?.length ? v.sort : ["price"] })}
            count={(v) => filterOf(v).length}
            subtitle={t("ofFilterTitle")}
            footer={(draft, setDraft) => {
              const field = (key: "minTotal" | "maxTotal" | "maxExcess", label: string, placeholder: string) => (
                <TextField
                  label={label}
                  value={draft[key]?.[0] ?? ""}
                  onChangeText={(v) => setDraft((d) => ({ ...d, [key]: v ? [v] : [] }))}
                  keyboardType="decimal-pad"
                  placeholder={placeholder}
                  error={amountInvalid(draft[key]?.[0]) ? t("ofAmountInvalid") : undefined}
                />
              );
              return (
                <Card>
                  <View style={st.range}>
                    <View style={st.flex}>{field("minTotal", t("ofMinPremium"), "0")}</View>
                    <View style={st.flex}>{field("maxTotal", t("ofMaxPremium"), t("ofNoLimit"))}</View>
                  </View>
                  {field("maxExcess", t("ofMaxExcess"), t("ofNoLimit"))}
                </Card>
              );
            }}
          />
        </>
      ) : null}

      {offers.length === 0 ? (
        <Card>
          <Text style={ps.title}>{t("ofNoneTitle")}</Text>
          <Text style={ps.meta}>{t("ofNoneBody")}</Text>
          <Button label={t("ofChangeDetails")} variant="secondary" onPress={editQuote} />
          <Button label={t("ofRetryRating")} variant="tertiary" loading={busy} disabled={busy} onPress={() => void doRerate()} />
        </Card>
      ) : visible.length === 0 ? (
        <EmptyState title={t("ofNoMatch")} message={t("ofNoMatchBody")} action={t("ofClearFilters")} onPress={clearFilters} />
      ) : (
        <>
          <Text style={ps.meta}>{t("ofShowing", { shown: visible.length, total: offers.length })}</Text>
          {compareNotice ? (
            <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={st.notice}>
              {t("ofCompareMax", { count: MAX_COMPARE })}
            </Text>
          ) : null}
          {visible.map((o) => {
            const choice = offerChoice(o, { acceptedId: pick.acceptedId, outcome, now });
            const choosable = choice.kind === "select";
            return (
              <OfferCard
                key={o.id}
                offer={o}
                all={offers}
                now={now}
                best={choosable && best === o.id}
                badge={choosable && o.total_minor === cheapest ? { label: t("ofLowest"), tone: "success" } : undefined}
                compareSelected={compare.includes(o.id)}
                compareFull={compare.length >= MAX_COMPARE}
                onToggleCompare={canCompare && choosable ? () => toggleCompare(o.id) : undefined}
                choice={choice}
                selecting={pick.choosing === o.id}
                opening={pick.opening}
                disabled={!!pick.choosing || busy}
                onSelect={() => void pick.choose(o)}
                onOpenApplication={() => void pick.openApplication()}
              />
            );
          })}
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  range: { flexDirection: "row", gap: space.x3 },
  sortBlock: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  focusRow: { flexDirection: "row", alignItems: "center", flexWrap: "wrap", gap: space.x2 },
  sortLabel: { ...type.label, color: colors.navy950 },
  notice: { ...type.meta, color: colors.warningText, backgroundColor: colors.warningSoft, borderRadius: radius.control, padding: space.x3 },
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
  },
  insurerName: { ...type.label, color: colors.navy950 },
  insurerPrice: { ...type.meta, fontFamily: "Inter_700Bold", color: colors.navy950, fontVariant: ["tabular-nums"] },
  muted: { ...type.meta, color: colors.neutral600 },
});
