import React, { useEffect, useMemo, useState } from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  ArrowRight,
  Building2,
  Car,
  ChevronRight,
  FileText,
  LucideIcon,
  Scale,
  Search as SearchIcon,
  ShieldCheck,
  Tag,
  Users,
} from "lucide-react-native";
import { Chip, Screen, StatusChip, ripple } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { CATEGORIES, Category } from "@/components/customer/categories";
import { SearchApi } from "@/api/crm";
import { CustomerApi } from "@/api/customer";
import type { Institution } from "@/api/extra";
import { useLoad } from "@/hooks/useLoad";
import { groupSearch, SEARCH_TYPES, SearchResponse, searchHitRoute, SearchRole, SearchType } from "@/lib/crm";
import { matchesQuery } from "@/lib/customerLogic";
import { FiltersSheet, type FilterValues } from "@/components/customer/FiltersSheet";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const ROLES: SearchRole[] = ["customer", "agent", "broker", "carrier"];
/** Local scopes (catalogue categories + licensed providers) shown to customers next to the API entity types. */
type Scope = "all" | "products" | "providers" | SearchType;
const HIT_ICONS: Record<string, LucideIcon> = {
  customers: Users,
  policies: ShieldCheck,
  claims: FileText,
  quotes: Tag,
  documents: FileText,
  vehicles: Car,
  risk_assets: Car,
};

/** REQ-SRC-001 global search (GET /search?q=&types[]=). ?role= picks the portal's detail screens. */
export default function GlobalSearch() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{ role?: string; q?: string }>();
  const role: SearchRole = ROLES.includes(params.role as SearchRole) ? (params.role as SearchRole) : "customer";
  const customer = role === "customer";
  const [text, setText] = useState(typeof params.q === "string" ? params.q : "");
  const [only, setOnly] = useState<Scope>("all");
  const [state, setState] = useState<{ loading: boolean; error: unknown; data: SearchResponse | null }>({ loading: false, error: null, data: null });
  const [tooShort, setTooShort] = useState(false);
  const [asked, setAsked] = useState("");
  // Customers also match the marketplace catalogue and the public provider register (GET /public/institutions).
  const providers = useLoad(() => (customer ? CustomerApi.institutions("insurer") : Promise.resolve([] as Institution[])), [customer]);

  const run = async (scope: Scope = only) => {
    const q = text.trim();
    setTooShort(q.length < 2);
    if (q.length < 2) return;
    setAsked(q);
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const apiScope = (SEARCH_TYPES as readonly string[]).includes(scope) ? [scope as SearchType] : [];
      const data = await SearchApi.search(q, apiScope, apiScope.length ? 20 : 5);
      setState({ loading: false, error: null, data });
    } catch (e) {
      setState({ loading: false, error: e, data: null });
    }
  };
  // A query handed over from Home / Explore runs straight away.
  useEffect(() => {
    if (typeof params.q === "string" && params.q.trim().length >= 2) void run("all");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.q]);
  const [sheet, setSheet] = useState(false);
  const sheetValue = useMemo<FilterValues>(() => ({ type: [only] }), [only]);
  const groups = groupSearch(state.data);
  const shownGroups = only === "all" ? groups : groups.filter((g) => g.type === only);

  const products = useMemo(
    () => (customer && asked ? CATEGORIES.filter((c) => c.id !== "more" && matchesQuery(asked, t(c.label), t(c.caption), td(`lineFamily_${c.id}`, t(c.label)), c.id)) : []),
    [asked, customer, t, td],
  );
  const matchedProviders = useMemo(
    () =>
      customer && asked
        ? (providers.data ?? []).filter((p) => matchesQuery(asked, p.name, p.short_name, p.city, p.code, ...(p.products ?? []).map((x) => `${x.name} ${x.line_code}`)))
        : [],
    [asked, customer, providers.data],
  );
  const providersForLine = (c: Category) => (providers.data ?? []).filter((p) => (p.products ?? []).some((x) => c.lines.includes((x.line_code ?? "").toUpperCase())));

  const apiCount = state.data ? (SEARCH_TYPES as readonly string[]).reduce((n, k) => n + (state.data?.counts?.[k] ?? groups.find((g) => g.type === k)?.hits.length ?? 0), 0) : 0;
  const total = apiCount + products.length + matchedProviders.length;
  const searched = !!state.data && !state.loading;
  const nothing = searched && !shownGroups.length && (only !== "all" && only !== "products" ? true : !products.length) && (only !== "all" && only !== "providers" ? true : !matchedProviders.length);
  const count = (k: string) => (state.data ? ` (${state.data.counts?.[k] ?? groups.find((g) => g.type === k)?.hits.length ?? 0})` : "");

  const scopes: { value: Scope; label: string }[] = [
    { value: "all", label: `${t("searchAll")}${state.data ? ` (${total})` : ""}` },
    ...(customer
      ? [
          { value: "products" as Scope, label: `${t("searchType_products")}${asked ? ` (${products.length})` : ""}` },
          { value: "providers" as Scope, label: `${t("searchType_providers")}${asked ? ` (${matchedProviders.length})` : ""}` },
        ]
      : []),
    // A customer searches only their own records: the CRM "customers" entity type is a staff scope.
    ...SEARCH_TYPES.filter((x) => !customer || x !== "customers").map((x) => ({ value: x as Scope, label: `${td(`searchType_${x}`, x)}${count(x)}` })),
  ];

  // Result counts per scope, for the filter sheet's live total.
  const scopeCount = (v: Scope) =>
    v === "all" ? total : v === "products" ? products.length : v === "providers" ? matchedProviders.length : state.data?.counts?.[v] ?? groups.find((g) => g.type === v)?.hits.length ?? 0;
  const openProduct = (c: Category) => router.push({ pathname: "/quote/product/[id]", params: { id: c.id } });
  const quoteProduct = (c: Category) => router.push({ pathname: "/quote/product", params: { product: c.id } });

  return (
    <Screen>
      <BrandHeader title={t("searchResultsTitle")} subtitle={searched && total ? t("searchFoundCount", { count: total, q: state.data?.query ?? asked }) : t("searchResultsSubtitle")} back right="bell" />
      <SearchBar
        value={text}
        onChangeText={setText}
        onSubmit={() => void run()}
        placeholder={t("globalSearchPlaceholder")}
        label={t("searchTitle")}
        clearLabel={t("clearSearch")}
        autoFocus={!text}
        onFilter={() => setSheet(true)}
        filterLabel={t("filtersTitle")}
        filterCount={only === "all" ? 0 : 1}
      />
      <FiltersSheet
        visible={sheet}
        onClose={() => setSheet(false)}
        sections={[{ key: "type", single: true, title: t("filterSearchType"), subtitle: t("filterSearchTypeBody"), options: scopes.map((o) => ({ value: o.value, label: o.label })) }]}
        value={sheetValue}
        onApply={(f: FilterValues) => {
          const next = (f.type?.[0] ?? "all") as Scope;
          setOnly(next);
          if (text.trim().length >= 2) void run(next);
        }}
        count={(f) => scopeCount((f.type?.[0] ?? "all") as Scope)}
        subtitle={t("filtersSearchSubtitle")}
      />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chips} accessibilityRole="tablist">
        {scopes.map((o) => (
          <Chip
            key={o.value}
            role="tab"
            label={o.label}
            selected={o.value === only}
            onPress={() => {
              setOnly(o.value);
              if (text.trim().length >= 2) void run(o.value);
            }}
          />
        ))}
      </ScrollView>
      {tooShort ? <Text style={s.meta}>{t("searchMinChars")}</Text> : null}
      {state.loading ? <LoadingState /> : null}
      {state.error ? <ErrorState error={state.error} onRetry={() => void run()} /> : null}
      {nothing ? <EmptyState title={t("searchResultsTitle")} message={t("searchNoResults", { q: state.data?.query ?? text })} /> : null}

      {searched && (only === "all" || only === "products") && products.length ? (
        <View style={s.section}>
          <SectionHeading title={`${t("searchInsuranceProducts")} (${products.length})`} />
          {products.map((c) => {
            const Icon = c.icon;
            const offering = providersForLine(c);
            return (
              <View key={c.id} style={s.card}>
                <View style={s.productRow}>
                  <View style={s.imageTile}>
                    <Icon size={40} color={colors.navy800} strokeWidth={1.6} />
                    <View style={s.imageTag}>
                      <Icon size={12} color={colors.white} />
                      <Text style={s.imageTagText}>{t(c.label)}</Text>
                    </View>
                  </View>
                  <View style={s.flex}>
                    <Text style={s.kicker}>{t("searchProductKicker", { line: t(c.label).toUpperCase() })}</Text>
                    <Text style={s.cardTitle}>{td(`lineFamily_${c.id}`, t(c.label))}</Text>
                    <Text style={s.body}>{t(c.caption)}</Text>
                    {offering.length ? (
                      <View style={s.logoRow}>
                        {offering.slice(0, 3).map((p) => (
                          <InstitutionMark key={p.id} logoUrl={institutionLogo(p)} initials={p.initials} size={26} />
                        ))}
                        <Text style={s.meta}>{t("pdLicensedProviders", { count: offering.length })}</Text>
                      </View>
                    ) : null}
                  </View>
                </View>
                <View style={s.actions}>
                  <Pressable accessibilityRole="button" onPress={() => openProduct(c)} style={({ pressed }) => [s.linkBtn, pressed && s.pressed]}>
                    <Text style={s.linkText}>{t("searchViewDetails")}</Text>
                    <ChevronRight size={18} color={colors.blue600} />
                  </Pressable>
                  <Pressable accessibilityRole="button" onPress={() => quoteProduct(c)} android_ripple={ripple(true)} style={({ pressed }) => [s.primaryBtn, pressed && s.pressed]}>
                    <Text style={s.primaryText}>{t("searchGetQuote")}</Text>
                    <ArrowRight size={18} color={colors.white} />
                  </Pressable>
                </View>
              </View>
            );
          })}
        </View>
      ) : null}

      {searched && (only === "all" || only === "providers") && matchedProviders.length ? (
        <View style={s.section}>
          <SectionHeading title={t("searchProvidersMatching", { q: asked })} action={t("seeAll")} onAction={() => router.push("/institutions/insurers" as never)} />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.strip}>
            {matchedProviders.map((p) => (
              <Pressable
                key={p.id}
                accessibilityRole="button"
                accessibilityLabel={p.name}
                onPress={() => router.push({ pathname: p.type === "insurer" ? "/institutions/insurer/[id]" : "/institutions/broker/[id]", params: { id: p.id } })}
                android_ripple={ripple()}
                style={({ pressed }) => [s.providerCard, pressed && s.pressed]}
              >
                <View style={s.providerTop}>
                  <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={56} />
                  <ChevronRight size={18} color={colors.blue600} />
                </View>
                <Text style={s.providerName} numberOfLines={2}>{p.name}</Text>
                <Text style={s.meta}>{p.products?.length ? (p.products.length === 1 ? t("productsCountOne") : t("productsCount", { count: p.products.length })) : p.city ?? ""}</Text>
              </Pressable>
            ))}
          </ScrollView>
        </View>
      ) : null}

      {!state.loading
        ? shownGroups.map((g) => (
            <View key={g.type} style={s.section}>
              <SectionHeading title={`${td(`searchType_${g.type}`, g.type)} (${state.data?.counts?.[g.type] ?? g.hits.length})`} />
              <View style={s.card}>
                {g.hits.map((h, i) => {
                  const href = searchHitRoute(h, role);
                  const Icon = HIT_ICONS[h.type] ?? SearchIcon;
                  const inner = (
                    <>
                      <TintedIcon icon={Icon} tint={h.type === "claims" ? "gold" : "blue"} size={44} />
                      <View style={s.flex}>
                        <Text style={s.rowTitle} numberOfLines={2}>{h.title}</Text>
                        {h.subtitle ? <Text style={s.meta} numberOfLines={2}>{h.subtitle}</Text> : null}
                        {h.status ? <View style={{ flexDirection: "row", marginTop: 4 }}><StatusChip label={td(`status_${h.status}`, h.status)} tone="info" /></View> : null}
                      </View>
                      {href ? <ChevronRight size={20} color={colors.neutral500} /> : null}
                    </>
                  );
                  const rowStyle = [s.hitRow, i > 0 && s.hitDivider];
                  return href ? (
                    <Pressable key={`${h.type}-${h.id}`} accessibilityRole="button" onPress={() => router.push(href as never)} android_ripple={ripple()} style={({ pressed }) => [rowStyle, pressed && s.pressed]}>
                      {inner}
                    </Pressable>
                  ) : (
                    <View key={`${h.type}-${h.id}`} style={rowStyle}>{inner}</View>
                  );
                })}
              </View>
            </View>
          ))
        : null}

      {searched ? (
        <Banner
          icon={customer ? Scale : Building2}
          tint="blue"
          title={t("searchHelpTitle")}
          body={customer ? t("searchHelpBody") : t("searchHelpSupportBody")}
          onPress={() => router.push((customer ? "/quote/compare" : "/support") as never)}
          right={
            <View style={s.bannerCta}>
              <Text style={s.bannerCtaText}>{customer ? t("searchHelpCta") : t("helpComplaints")}</Text>
              <ArrowRight size={16} color={colors.white} />
            </View>
          }
        />
      ) : null}
    </Screen>
  );
}


const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral600 },
  chips: { flexDirection: "row", gap: space.x2, paddingRight: space.x2 },
  section: { gap: space.x3 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  productRow: { flexDirection: "row", gap: space.x3 },
  imageTile: { width: 112, height: 112, borderRadius: radius.card, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center", overflow: "hidden" },
  imageTag: { position: "absolute", left: 8, bottom: 8, flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: colors.navy950, borderRadius: radius.pill, paddingHorizontal: 8, paddingVertical: 3 },
  imageTagText: { ...type.caption, color: colors.white },
  kicker: { ...type.eyebrow, color: colors.blue600, marginBottom: 2 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  logoRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: space.x2 },
  actions: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  linkBtn: { flexDirection: "row", alignItems: "center", gap: 2, minHeight: 44, paddingHorizontal: space.x1 },
  linkText: { ...type.label, color: colors.blue600 },
  primaryBtn: { flex: 1, minHeight: 46, borderRadius: radius.control, backgroundColor: colors.blue600, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  primaryText: { ...type.label, color: colors.white },
  strip: { gap: space.x3, paddingRight: space.x2 },
  providerCard: { width: 150, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, gap: space.x2, overflow: "hidden" },
  providerTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  providerName: { ...type.label, color: colors.navy950 },
  hitRow: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x3 },
  hitDivider: { borderTopWidth: 1, borderTopColor: colors.neutral200 },
  rowTitle: { ...type.label, color: colors.navy950 },
  bannerCta: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.blue600, borderRadius: radius.control, paddingHorizontal: space.x3, paddingVertical: space.x2 },
  bannerCtaText: { ...type.label, color: colors.white, fontSize: 13 },
});
