import React, { useEffect, useMemo, useState } from "react";
import { Alert, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { router } from "expo-router";
import { ArrowRight, ArrowLeftRight, Briefcase, CalendarDays, Car, ChevronRight, Clock3, HardHat, HeartPulse, Home, LayoutGrid, LucideIcon, Plane, ShieldPlus, Trash2 } from "lucide-react-native";
import { Button, Chip, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, TintedIcon } from "@/components/design";
import { byDate, byNumber, FilterToolbar, optionsFrom, periodMatcher, periodSection, runList, sortSection, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { InstitutionMark } from "@/components/InstitutionMark";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { CustomerQuoteSummary, QuotesApi } from "@/api/client";
import { useListState } from "@/hooks/useListState";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize } from "@/lib/purchase";
import { daysUntil, isExpiringSoon, lineFamily, LineFamily } from "@/lib/crm";
import { useTranslation } from "@/i18n";
import { quoteOutcome, quoteTone } from "@/lib/quoteWorkflow";
import { colors, radius, space, type } from "@/theme/tokens";

const LINE_ICONS: Record<LineFamily, LucideIcon> = { motor: Car, health: HeartPulse, travel: Plane, home: Home, business: Briefcase, life: ShieldPlus, accident: HardHat };
/** Filter chips from the design; the rest of the families stay reachable through "All". */
const FILTERS: { value: "all" | LineFamily; icon: LucideIcon; label: "filterAll" | "catMotor" | "catHealth" | "catLife" | "catTravel" }[] = [
  { value: "all", icon: LayoutGrid, label: "filterAll" },
  { value: "motor", icon: Car, label: "catMotor" },
  { value: "health", icon: HeartPulse, label: "catHealth" },
  { value: "life", icon: ShieldPlus, label: "catLife" },
  { value: "travel", icon: Plane, label: "catTravel" },
];

type Row = CustomerQuoteSummary & { created_at?: string | null; carrier_name?: string | null; provider_name?: string | null; carrier_logo_url?: string | null };

export default function QuoteHistory() {
  const { t, td } = useTranslation();
  const narrow = useWindowDimensions().width < 400;
  const f = useFormatters();
  const list = usePagedList<CustomerQuoteSummary>((page) => QuotesApi.history(page));
  // NAV-002: keep scroll position and refetch on return from a quote.
  const listState = useListState("customer.quotes", list.reload);
  const [deleting, setDeleting] = useState<string | null>(null);
  const [actionError, setActionError] = useState<unknown>(null);

  // Shared list standard (FLT-001..006): family tabs + sheet (status, period, sort) + accent-insensitive search.
  const rows = list.items as Row[];
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "family", title: t("filterCategory"), options: optionsFrom(rows, (q) => { const fam = lineFamily(q.line_code); return fam ? { value: fam, label: td(`lineFamily_${fam}`, fam) } : null; }) },
      { key: "status", title: t("filterStatus"), options: optionsFrom(rows, (q) => { const k = quoteOutcome(q) ?? q.status; return { value: k, label: td(`quoteStatus_${k}`, k) }; }) },
      periodSection(t, "period", t("fltCreated")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "price", label: t("fltSortAmountLow") },
      ]),
    ],
    [rows, t, td],
  );
  const flt = useListFilters("customer.quotes", sections);
  const matchers: Matchers<Row> = {
    family: (q, v) => lineFamily(q.line_code) === v,
    status: (q, v) => (quoteOutcome(q) ?? q.status) === v,
    period: periodMatcher((q) => q.created_at),
  };
  const sorters: Sorters<Row> = { recent: byDate((q) => q.created_at), oldest: byDate((q) => q.created_at, "asc"), price: byNumber((q) => q.lowest_total_minor, "asc") };
  const haystack = (q: Row) => [q.product_name, q.quote_number, q.vehicle_label, q.line_code, q.carrier_name, q.provider_name, td(`quoteStatus_${q.status}`, q.status)];
  const run = (v: FilterValues) => runList(rows, { values: v, text: flt.query, matchers, haystack, sorters });
  const shown = run(flt.values);
  const famSel = flt.values.family ?? [];
  const family = famSel.length === 1 ? famSel[0] : famSel.length ? null : "all";
  const setFamily = (v: string) => flt.setValues({ ...flt.values, family: v === "all" ? [] : [v] });
  // No server-side filters on /mobile/quotes: while filtering, fetch every page so no match is hidden on a later page.
  const { hasMore, loadAll } = list;
  useEffect(() => {
    if (flt.active && hasMore) void loadAll();
  }, [flt.active, hasMore, loadAll]);

  const remove = (q: Row) =>
    Alert.alert(t("qtRemoveQ"), t("qtRemoveBody"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t("quotesDelete"),
        style: "destructive",
        onPress: async () => {
          setDeleting(q.id);
          setActionError(null);
          try {
            await QuotesApi.discard(q.id);
            await list.reload();
          } catch (e) {
            setActionError(e);
          } finally {
            setDeleting(null);
          }
        },
      },
    ]);

  return (
    <Screen scroll={false}>
      <FlatList
        {...listState}
        data={shown}
        keyExtractor={(q) => q.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={s.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.moreError) void list.loadMore();
        }}
        ListHeaderComponent={
          <View style={s.header}>
            <BrandHeader title={t("quotesTitle")} subtitle={t("quotesSubtitle")} back right="bell" />
            <FilterToolbar
              filters={flt}
              sections={sections}
              placeholder={t("quotesSearchPlaceholder")}
              count={(v) => run(v).length}
              resultCount={flt.active ? shown.length : undefined}
              quick={
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chips} accessibilityRole="tablist">
                  {FILTERS.map((o) => (
                    <Chip key={o.value} role="tab" label={t(o.label)} selected={family === o.value} onPress={() => setFamily(o.value)} />
                  ))}
                </ScrollView>
              }
            />
            {list.fetchingAll ? <Text style={s.meta}>{t("fltLoadingAll")}</Text> : null}
            {list.loading && !list.items.length ? <LoadingState label={t("quotesLoading")} /> : null}
            {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("quotesLoadFailed")} onRetry={() => void list.reload()} /> : null}
            {actionError ? <ErrorCard error={actionError} fallback={t("actionFailed")} /> : null}
          </View>
        }
        renderItem={({ item }) => {
          const q = item as Row;
          const fam = lineFamily(q.line_code);
          const Icon = fam ? LINE_ICONS[fam] : Clock3;
          const outcome = quoteOutcome(q);
          const days = daysUntil(q.expires_at);
          const soon = !outcome && isExpiringSoon(q.expires_at);
          const provider = q.carrier_name ?? q.provider_name ?? null;
          const open = () => router.push({ pathname: "/quotes/[id]", params: { id: q.id } });
          return (
            <View style={s.card}>
              <View style={s.top}>
                <View style={s.imageTile}>
                  <Icon size={34} color={colors.navy800} strokeWidth={1.5} />
                </View>
                <View style={s.flex}>
                  <View style={s.titleRow}>
                    <TintedIcon icon={Icon} tint="blue" size={40} />
                    <View style={s.flex}>
                      <Text style={s.line}>{fam ? td(`lineFamily_${fam}`, humanize(q.line_code)) : humanize(q.line_code)}</Text>
                      {provider ? (
                        <View style={s.providerRow}>
                          <InstitutionMark logoUrl={q.carrier_logo_url ?? null} initials={provider.slice(0, 2).toUpperCase()} size={20} />
                          <Text style={[s.meta, s.flex]}>{provider}</Text>
                        </View>
                      ) : q.quote_number ? <Text style={s.meta}>{q.quote_number}</Text> : null}
                    </View>
                  </View>
                  <View style={s.chipRow}>
                    {soon ? (
                      <View style={s.soon}>
                        <Clock3 size={14} color={colors.gold600} />
                        <Text style={s.soonText}>{t("quotesExpiringSoon")}</Text>
                      </View>
                    ) : (
                      <StatusChip label={td(`quoteStatus_${outcome ?? q.status}`, q.status)} tone={quoteTone(q)} />
                    )}
                  </View>
                </View>
              </View>
              <View style={s.nameRow}>
                <Text style={[s.title, s.flex]}>{q.product_name ?? q.vehicle_label ?? humanize(q.line_code)}</Text>
                {typeof q.lowest_total_minor === "number" ? (
                  <View style={s.priceBox}>
                    <Text style={s.price}>{(q.offer_count ?? 0) > 1 ? t("quotesFromPrice", { price: f.xaf(q.lowest_total_minor) }) : f.xaf(q.lowest_total_minor)}</Text>
                    <Text style={s.meta}>{t("quotesPerYear")}</Text>
                  </View>
                ) : null}
              </View>
              {q.vehicle_label && q.product_name ? <Text style={s.meta}>{q.vehicle_label}</Text> : null}
              {q.offer_count != null ? <Text style={s.meta}>{t("quotesOfferCount", { count: q.offer_count })}</Text> : null}
              <View style={s.metaGrid}>
                <View style={[s.metaCell, s.flex]}>
                  <CalendarDays size={20} color={colors.navy800} />
                  <View style={s.flex}>
                    {outcome ? (
                      <Text style={s.metaStrong}>{td(`quoteStatus_${outcome}`, outcome)}</Text>
                    ) : days === null ? (
                      <Text style={s.metaStrong}>{t("quotesValidUntil", { date: f.date(q.expires_at) })}</Text>
                    ) : days <= 0 ? (
                      <Text style={[s.metaStrong, s.gold]}>{t("quotesExpiresToday")}</Text>
                    ) : (
                      <Text style={[s.metaStrong, soon && s.gold]}>{t("quotesExpiresIn", { days })}</Text>
                    )}
                  </View>
                </View>
                {q.created_at ? (
                  <View style={[s.metaCell, s.flex, s.metaDivider]}>
                    <CalendarDays size={20} color={colors.navy800} />
                    <Text style={s.metaStrong}>{t("quotesSavedOn", { date: f.date(q.created_at) })}</Text>
                  </View>
                ) : null}
                <Pressable accessibilityRole="button" accessibilityLabel={t(q.can_resume === false ? "quotesViewQuote" : "quotesResume")} hitSlop={8} onPress={open}>
                  <ChevronRight size={20} color={colors.navy800} />
                </Pressable>
              </View>
              <View style={[s.actions, narrow && s.actionsWrap]}>
                {/* BTN-002: shared Button variants instead of local pills. */}
                {q.can_resume === false ? (
                  <Button size="small" variant="secondary" icon={ArrowRight} iconPosition="left" label={t("quotesViewQuote")} onPress={open} style={[s.btnFlex, s.btnGrow, narrow && s.fullRow]} />
                ) : (
                  <Button size="small" variant="gold" icon={ArrowRight} iconPosition="left" label={t("quotesResume")} onPress={open} style={[s.btnFlex, s.btnGrow, narrow && s.fullRow]} />
                )}
                <Button
                  size="small"
                  variant="secondary"
                  icon={ArrowLeftRight}
                  label={t("quotesCompare")}
                  onPress={() => router.push({ pathname: "/quote-comparison/[id]", params: { id: q.id } })}
                  style={s.btnFlex}
                />
                <Button
                  size="small"
                  variant="danger"
                  icon={Trash2}
                  label={t("quotesDelete")}
                  loading={deleting === q.id}
                  disabled={!!deleting}
                  onPress={() => remove(q)}
                  style={s.btnFlex}
                />
              </View>
            </View>
          );
        }}
        ListEmptyComponent={
          !list.loading && !list.error ? (
            list.items.length ? (
              <EmptyState title={t("quotesNoMatch")} message={t("quotesEmptyBody")} action={t("fltClearAll")} onPress={flt.clear} />
            ) : (
              <EmptyState title={t("quotesEmpty")} message={t("quotesEmptyBody")} action={t("quotesGetQuote")} onPress={() => router.push("/quote/product")} />
            )
          ) : null
        }
        ListFooterComponent={
          <View style={s.footer}>
            <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
            {list.items.length ? (
              <Banner
                icon={HeartPulse}
                tint="blue"
                title={t("searchHelpTitle")}
                body={t("quotesHelpBody")}
                onPress={() => router.push("/(customer)/(tabs)/explore" as never)}
                right={
                  <View style={s.bannerCta}>
                    <Text style={s.bannerCtaText}>{t("quotesExplore")}</Text>
                    <ArrowRight size={16} color={colors.navy950} />
                  </View>
                }
              />
            ) : null}
          </View>
        }
      />
    </Screen>
  );
}
const s = StyleSheet.create({
  providerRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  content: { paddingBottom: space.x16, gap: space.x4 },
  header: { gap: space.x4 },
  chips: { flexDirection: "row", gap: space.x2, paddingRight: space.x2 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  top: { flexDirection: "row", gap: space.x3 },
  imageTile: { width: 72, height: 72, borderRadius: radius.card, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  titleRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  line: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  chipRow: { flexDirection: "row", marginTop: space.x2 },
  soon: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.gold50, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 5 },
  soonText: { ...type.caption, color: colors.gold600 },
  nameRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  priceBox: { alignItems: "flex-end" },
  price: { fontFamily: "Inter_700Bold", fontSize: 20, lineHeight: 26, color: colors.navy950, fontVariant: ["tabular-nums"] },
  metaGrid: { flexDirection: "row", alignItems: "center", gap: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  metaCell: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  metaDivider: { borderLeftWidth: 1, borderLeftColor: colors.neutral200, paddingLeft: space.x3 },
  metaStrong: { ...type.meta, color: colors.navy950 },
  gold: { color: colors.gold600, fontFamily: "Inter_700Bold" },
  actions: { flexDirection: "row", gap: space.x2 },
  actionsWrap: { flexWrap: "wrap" },
  fullRow: { flexBasis: "100%" },
  btnFlex: { flex: 1, paddingHorizontal: space.x2 },
  btnGrow: { flex: 1.3 },
  btn: { flex: 1, minHeight: 46, borderRadius: radius.control, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, overflow: "hidden", paddingHorizontal: space.x2 },
  gold_btn: { flex: 1.3, backgroundColor: colors.gold500 },
  soft_btn: { backgroundColor: colors.blue50 },
  deleteBtn: { flex: 0.9 },
  btnText: { ...type.label, color: colors.navy950, fontSize: 13 },
  footer: { gap: space.x4 },
  bannerCta: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.gold500, borderRadius: radius.control, paddingHorizontal: space.x3, paddingVertical: space.x2 },
  bannerCtaText: { ...type.label, color: colors.navy950, fontSize: 13 },
});
