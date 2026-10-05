import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ArrowRight, Briefcase, CalendarDays, CarFront, CheckCircle2, ChevronRight, Clock3, FileText, HardHat, HeartPulse, Home, Info, LoaderCircle, LucideIcon, Plane, ShieldPlus } from "lucide-react-native";
import { Button, Screen, ripple } from "@/components/ui";
import { Banner, BrandHeader, TintedIcon } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { ApiError, InsuranceApi, ProposalSummary, ProposalsApi } from "@/api/client";
import { ProposalLifecycleApi } from "@/api/workflow";
import { RecentProposals } from "@/store/insurance";
import { localized, mergePages, proposalStatusInfo } from "@/lib/purchase";
import { checklistProgress, draftBucket, DraftBucket, lineFamily, LineFamily } from "@/lib/crm";
import { useFormatters } from "@/hooks/useFormatters";
import { byDate, FilterToolbar, optionsFrom, periodMatcher, periodSection, runList, sortSection, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, tileIcon, tileIconSize, type } from "@/theme/tokens";

const LINE_ICONS: Record<LineFamily, LucideIcon> = { motor: CarFront, health: HeartPulse, travel: Plane, home: Home, business: Briefcase, life: ShieldPlus, accident: HardHat };
const LINE_TINT: Record<LineFamily, "blue" | "gold" | "red" | "green" | "neutral"> = { motor: "gold", health: "red", travel: "blue", home: "green", business: "neutral", life: "blue", accident: "gold" };
type Filter = "all" | Exclude<DraftBucket, "other">;
const FILTERS: { value: Filter; label: "draftsAll" | "draftsInProgress" | "draftsAwaiting" | "draftsReady" }[] = [
  { value: "all", label: "draftsAll" },
  { value: "progress", label: "draftsInProgress" },
  { value: "awaiting", label: "draftsAwaiting" },
  { value: "ready", label: "draftsReady" },
];
type Row = ProposalSummary & { carrier_short_name?: string | null; updated_at?: string | null; carrier_logo_url?: string | null; line_code?: string | null; risk_summary?: string | null; vehicle_label?: string | null };

/**
 * "Draft applications". Uses GET /mobile/proposals when the backend has it;
 * otherwise falls back to the proposals this device opened (each re-read
 * from the server, so status is always current and ownership enforced).
 */
export default function Applications() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const [items, setItems] = useState<ProposalSummary[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [error, setError] = useState<unknown>(null);
  /** Checklist completion per proposal (GET /proposals/{id}/checklist); missing when the API has none. */
  const [progress, setProgress] = useState<Record<string, number>>({});

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await ProposalsApi.list(1);
      setItems(result.items);
      setPage(1);
      setHasMore(result.info.hasMore);
    } catch (e) {
      if (!(e instanceof ApiError) || (e.status !== 404 && e.status !== 405)) {
        setError(e);
      } else {
        const ids = await RecentProposals.list();
        const found = await Promise.all(ids.map((id) => InsuranceApi.proposal(id).catch(() => null)));
        setItems(found.filter((x): x is NonNullable<typeof x> => !!x));
        setHasMore(false);
      }
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);

  // Progress: GET /mobile/proposals rows carry required_documents for open applications (no per-row call);
  // the checklist endpoint is only a fallback for rows from an older server without it.
  useEffect(() => {
    let alive = true;
    const pending = items.filter((p) => progress[p.id] === undefined && !Array.isArray(p.required_documents) && draftBucket(p.status) !== "other");
    if (!pending.length) return;
    void Promise.allSettled(pending.map((p) => ProposalLifecycleApi.checklist(p.id).then((c) => [p.id, checklistProgress(c.required_documents)] as const))).then((results) => {
      if (!alive) return;
      const next: Record<string, number> = {};
      for (const r of results) if (r.status === "fulfilled" && r.value[1] !== null) next[r.value[0]] = r.value[1];
      for (const p of pending) if (next[p.id] === undefined) next[p.id] = -1; // known: no checklist
      setProgress((cur) => ({ ...cur, ...next }));
    });
    return () => {
      alive = false;
    };
  }, [items, progress]);

  const loadMore = async () => {
    setMore(true);
    try {
      const result = await ProposalsApi.list(page + 1);
      setItems((x) => mergePages(x, result.items));
      setPage(page + 1);
      setHasMore(result.info.hasMore);
    } catch (e) {
      setError(e);
    } finally {
      setMore(false);
    }
  };

  // A proposal whose policy is issued is no longer a draft: it lives under My policies.
  const drafts = useMemo(() => items.filter((p) => !p.policy_id), [items]);
  const issuedCount = items.length - drafts.length;
  // Shared list standard (FLT-001..006): status tabs + sheet (product family, insurer, period, sort) + search.
  const rows = drafts as Row[];
  const lineOf = (p: Row) => lineFamily(p.line_code ?? p.offer?.quote?.line_code ?? null);
  const sections = useMemo<FilterSection[]>(
    () => [
      // Former inline status tabs (In progress / Awaiting / Ready): now the first sheet section.
      { key: "bucket", title: t("filterStatus"), options: FILTERS.filter((o) => o.value !== "all" && rows.some((p) => draftBucket(p.status) === o.value)).map((o) => ({ value: o.value, label: t(o.label, { count: rows.filter((p) => draftBucket(p.status) === o.value).length }) })) },
      { key: "family", title: t("filterCategory"), options: optionsFrom(rows, (p) => { const fam = lineOf(p); return fam ? { value: fam, label: td(`lineFamily_${fam}`, fam) } : null; }) },
      { key: "provider", title: t("filterProvider"), options: optionsFrom(rows, (p) => (p.carrier_name ? { value: p.carrier_name, label: p.carrier_name } : null)) },
      periodSection(t, "period", t("fltCreated")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
      ]),
    ],
    [rows, t, td],
  );
  const flt = useListFilters("customer.proposals", sections);
  const dateOf = (p: Row) => p.updated_at ?? p.submitted_at ?? p.created_at ?? null;
  const matchers: Matchers<Row> = {
    bucket: (p, v) => draftBucket(p.status) === v,
    family: (p, v) => lineOf(p) === v,
    provider: (p, v) => p.carrier_name === v,
    period: periodMatcher((p) => p.created_at),
  };
  const sorters: Sorters<Row> = { recent: byDate(dateOf), oldest: byDate(dateOf, "asc") };
  const haystack = (p: Row) => [p.product_name, localized(p.offer?.product?.name, f.language), p.carrier_name, p.proposal_number, p.vehicle_label, p.risk_summary, proposalStatusInfo(p.status, f.language).label];
  const run = (v: FilterValues) => runList(rows, { values: v, text: flt.query, matchers, haystack, sorters });
  const shown = run(flt.values);
  // No server-side filters on /mobile/proposals: while filtering, keep fetching pages so no match is hidden.
  useEffect(() => {
    if (flt.active && hasMore && !more && !loading && !error) void loadMore();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [flt.active, hasMore, more, loading, error]);

  const openProposal = (p: Row) => {
    const status = (p.status ?? "").toUpperCase();
    if (status === "INFORMATION_REQUIRED") router.push({ pathname: "/proposals/[id]/information", params: { id: p.id } });
    else router.push({ pathname: "/proposals/[id]", params: { id: p.id } });
  };

  return (
    <Screen>
      <BrandHeader title={t("draftsTitle")} subtitle={t("draftsSubtitle")} back right="bell" />
      <FilterToolbar
        filters={flt}
        sections={sections}
        placeholder={t("fltSearchProposals")}
        count={(v) => run(v).length}
        resultCount={flt.active ? shown.length : undefined}
      />
      <Banner icon={Info} tint="blue" title={t("draftsAutoSaveTitle")} body={t("draftsAutoSaveBody")} right={<FileText size={40} color={colors.blue100} />} />
      {loading && !items.length ? <LoadingState label={t("propLoading")} /> : null}
      {error ? <ErrorCard error={error} fallback={t("propLoadFailed")} onRetry={() => void load()} /> : null}
      {issuedCount ? (
        <Pressable accessibilityRole="link" onPress={() => router.push("/(customer)/(tabs)/policies")} android_ripple={ripple()} style={({ pressed }) => [s.issued, pressed && s.pressed]}>
          <CheckCircle2 size={18} color={colors.successText} />
          <Text style={[s.body, s.flex]}>{t("draftsIssuedNote", { count: issuedCount })}</Text>
          <ChevronRight size={18} color={colors.navy800} />
        </Pressable>
      ) : null}
      {!loading && !error && !drafts.length ? (
        <EmptyState title={t("propEmpty")} message={t("propEmptyBody")} action={t("propGetQuote")} onPress={() => router.push("/quote/product")} />
      ) : null}
      {!loading && drafts.length && !shown.length ? <EmptyState title={t("draftsNoneInFilter")} message={t("propEmptyBody")} action={t("fltClearAll")} onPress={flt.clear} /> : null}
      {shown.map((row) => {
        const p = row as Row;
        const info = proposalStatusInfo(p.status, f.language);
        const bucket = draftBucket(p.status);
        const name = p.product_name ?? (localized(p.offer?.product?.name, f.language) || t("propFallbackName"));
        const lineCode = p.line_code ?? p.offer?.quote?.line_code ?? null;
        const fam = lineFamily(lineCode);
        const Icon = fam ? LINE_ICONS[fam] : FileText;
        const provider = p.carrier_short_name ?? p.carrier_name ?? null;
        const fetched = progress[p.id];
        const pct: number | null = checklistProgress(p.required_documents) ?? (typeof fetched === "number" && fetched >= 0 ? fetched : null);
        const subtitle = [p.vehicle_label ?? p.risk_summary ?? null, p.proposal_number, p.terms_snapshot?.total_minor ? f.xaf(p.terms_snapshot.total_minor) : null].filter(Boolean).join(" • ");
        const updated = p.updated_at ?? p.submitted_at ?? p.created_at ?? null;
        const cs = p.terms_snapshot?.coverage_starts_at, ce = p.terms_snapshot?.coverage_ends_at;
        const period = cs && ce ? t("draftsCoverPeriod", { start: f.date(cs), end: f.date(ce) }) : null;
        const submitted = p.submitted_at ? t("draftsSubmittedOn", { date: f.date(p.submitted_at) }) : null;
        const StatusIcon = bucket === "ready" ? CheckCircle2 : bucket === "awaiting" ? Clock3 : LoaderCircle;
        const statusStyle = bucket === "ready" ? s.statusGreen : bucket === "awaiting" ? s.statusGold : info.tone === "danger" ? s.statusRed : s.statusBlue;
        const statusText = bucket === "ready" ? s.statusGreenText : bucket === "awaiting" ? s.statusGoldText : info.tone === "danger" ? s.statusRedText : s.statusBlueText;
        const action = bucket === "awaiting" ? t("draftsUpload") : bucket === "ready" ? ((p.status ?? "").toUpperCase() === "COUNTEROFFERED" ? t("draftsReview") : t("prReviewPay")) : bucket === "progress" ? t("draftsResume") : t("draftsOpen");
        return (
          <View key={p.id} style={s.card}>
            <View style={s.top}>
              <View style={[s.iconTile, { backgroundColor: fam ? TINT_BG[LINE_TINT[fam]] : colors.neutral100 }]}>
                <Icon size={tileIconSize(60)} color={fam ? TINT_FG[LINE_TINT[fam]] : colors.navy800} strokeWidth={tileIcon.stroke} />
              </View>
              <View style={s.flex}>
                <View style={[s.status, statusStyle]}>
                  <StatusIcon size={14} color={statusText.color} />
                  <Text style={[s.statusLabel, statusText]}>{info.label}</Text>
                </View>
                <Text style={s.title}>{fam ? td(`lineFamily_${fam}`, name) : name}</Text>
                {provider ? (
                  <View style={s.providerRow}>
                    <InstitutionMark logoUrl={p.carrier_logo_url} initials={provider.slice(0, 2).toUpperCase()} size={22} />
                    <Text style={s.body} numberOfLines={1}>{provider}</Text>
                  </View>
                ) : null}
                <Text style={s.meta}>{fam && name !== td(`lineFamily_${fam}`, name) ? `${name}${subtitle ? ` • ${subtitle}` : ""}` : subtitle}</Text>
                {period || submitted ? <Text style={s.meta}>{[period, submitted].filter(Boolean).join(" • ")}</Text> : null}
              </View>
              <Pressable accessibilityRole="button" accessibilityLabel={t("draftsOpen")} onPress={() => openProposal(p)} hitSlop={8} style={s.chevron}>
                <ChevronRight size={18} color={colors.navy800} />
              </Pressable>
            </View>
            <View style={s.bottom}>
              <View style={s.bottomInfo}>
                {pct !== null ? (
                  <View style={s.progressWrap} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(pct * 100) }}>
                    <Text style={s.progressLabel}>{t("draftsComplete", { percent: Math.round(pct * 100) })}</Text>
                    <View style={s.track}>
                      <View style={[s.fill, { width: `${Math.max(4, Math.round(pct * 100))}%` }]} />
                    </View>
                  </View>
                ) : null}
                {updated ? (
                  <View style={s.updatedRow}>
                    <TintedIcon icon={CalendarDays} tint="blue" size={28} />
                    <Text style={[s.meta, s.flex]}>{t("draftsLastUpdated", { date: f.date(updated) })}</Text>
                  </View>
                ) : null}
              </View>
              <Button variant={bucket === "awaiting" ? "gold" : "primary"} icon={ArrowRight} label={action} onPress={() => openProposal(p)} />
            </View>
          </View>
        );
      })}
      <LoadMore hasMore={hasMore} loading={more} onPress={() => void loadMore()} />
    </Screen>
  );
}

const TINT_BG = { blue: colors.blue50, gold: colors.gold50, red: colors.dangerSoft, green: colors.successSoft, neutral: colors.neutral100 } as const;
const TINT_FG = { blue: colors.blue600, gold: colors.gold600, red: colors.danger, green: colors.success, neutral: colors.navy800 } as const;

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  issued: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 48, paddingHorizontal: space.x4, borderRadius: radius.card, backgroundColor: colors.successSoft, overflow: "hidden" },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x4 },
  top: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  iconTile: { width: 60, height: 60, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  title: { ...type.cardTitle, color: colors.navy950 },
  providerRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 4 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  status: { flexDirection: "row", alignSelf: "flex-start", alignItems: "center", gap: 6, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4, marginBottom: 6, maxWidth: "100%" },
  statusLabel: { ...type.caption },
  statusBlue: { backgroundColor: colors.blue50 },
  statusBlueText: { color: colors.blue700 },
  statusGold: { backgroundColor: colors.gold50 },
  statusGoldText: { color: colors.gold600 },
  statusGreen: { backgroundColor: colors.successSoft },
  statusGreenText: { color: colors.successText },
  statusRed: { backgroundColor: colors.dangerSoft },
  statusRedText: { color: colors.dangerText },
  chevron: { width: 36, height: 36, borderRadius: 18, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  bottom: { flexDirection: "row", flexWrap: "wrap", alignItems: "flex-end", gap: space.x3 },
  bottomInfo: { flexGrow: 1, flexBasis: 150 },
  progressWrap: { gap: 6 },
  progressLabel: { ...type.label, color: colors.navy950 },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.neutral200, overflow: "hidden" },
  fill: { height: 8, borderRadius: 4, backgroundColor: colors.blue600 },
  updatedRow: { flexDirection: "row", alignItems: "center", gap: space.x2, marginTop: space.x2 },
  btn: { flexGrow: 1, minHeight: 48, borderRadius: radius.card, paddingHorizontal: space.x4, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  btnNavy: { backgroundColor: colors.navy900 },
  btnGold: { backgroundColor: colors.gold500 },
  btnText: { ...type.label },
  btnTextLight: { color: colors.white },
  btnTextDark: { color: colors.navy950 },
});
