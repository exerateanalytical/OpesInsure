import React, { useEffect, useMemo, useState } from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ChevronRight, Scale, ShieldCheck } from "lucide-react-native";
import { Chip, ChipRow, ripple, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, SectionHeading } from "@/components/design";
import { CategoryStrip, CATEGORY_TINT } from "@/components/customer/CategoryTiles";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { SearchBar } from "@/components/SearchBar";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { CATEGORIES } from "@/components/customer/categories";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import type { Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { matchesQuery } from "@/lib/customerLogic";
import { activeFilterCount, FiltersSheet, type FilterValues } from "@/components/customer/FiltersSheet";
import { applyExploreFilters, exploreSections, listParam } from "@/components/customer/exploreFilters";
import { colors, radius, space, type } from "@/theme/tokens";

type Filter = "all" | "insurer" | "broker";

/** Marketplace: search across product categories and licensed providers
 * (GET /public/institutions), with the comparison entry point on top. */
export default function Explore() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{ q?: string; cat?: string; prov?: string; sort?: string }>();
  const [query, setQuery] = useState(typeof params.q === "string" ? params.q : "");
  const [filter, setFilter] = useState<Filter>("all");
  const [sheet, setSheet] = useState(false);
  const [extra, setExtra] = useState<FilterValues>({ cat: listParam(params.cat), prov: listParam(params.prov), sort: [params.sort === "name" ? "name" : "best"] });
  const providers = useLoad(() => CustomerApi.institutions());

  useEffect(() => {
    if (typeof params.q === "string") setQuery(params.q);
  }, [params.q]);
  // Filters handed over from the Home filter sheet.
  useEffect(() => {
    if (params.cat !== undefined || params.prov !== undefined || params.sort !== undefined)
      setExtra({ cat: listParam(params.cat), prov: listParam(params.prov), sort: [params.sort === "name" ? "name" : "best"] });
  }, [params.cat, params.prov, params.sort]);
  const sections = useMemo(() => exploreSections(providers.data ?? [], t), [providers.data, t]);
  const filtered = useMemo(() => applyExploreFilters(providers.data ?? [], extra), [providers.data, extra]);

  const categories = CATEGORIES.filter(
    (c) => c.id !== "more" && (!extra.cat?.length || extra.cat.includes(c.id)) && matchesQuery(query, t(c.label), t(c.caption), c.id),
  );
  // Official DGTCFM/MINFI register counts (29 insurers / 123 brokers when seeded).
  const registerTotals = useMemo(() => {
    const official = (providers.data ?? []).filter((p: Institution) => p.is_official_register);
    return {
      insurers: official.filter((p) => p.type === "insurer").length,
      brokers: official.filter((p) => p.type === "broker").length,
    };
  }, [providers.data]);
  // Insurers with products on the marketplace first, then the rest (max 8).
  const featured = useMemo(
    () =>
      (providers.data ?? [])
        .filter((p: Institution) => p.type === "insurer")
        .sort((x, y) => (y.products?.length ?? 0) - (x.products?.length ?? 0))
        .slice(0, 8),
    [providers.data],
  );
  const shown = useMemo(
    () =>
      filtered.filter(
        (p: Institution) =>
          (filter === "all" || p.type === filter) &&
          matchesQuery(query, p.name, p.short_name, p.city, p.code, ...(p.products ?? []).map((x) => `${x.name} ${x.line_code}`)),
      ),
    [filtered, filter, query],
  );

  return (
    <Screen>
      <BrandHeader back={false} title={t("exploreTitle")} subtitle={t("exploreTagline")} />
      <SearchBar
        value={query}
        onChangeText={setQuery}
        label={t("searchLabel")}
        placeholder={t("exploreSearchPlaceholder")}
        clearLabel={t("clearSearch")}
        onSubmit={() => (query.trim().length >= 2 ? router.push({ pathname: "/search", params: { q: query.trim() } }) : undefined)}
        onFilter={() => setSheet(true)}
        filterLabel={t("filtersTitle")}
        filterCount={activeFilterCount(extra, sections)}
      />
      <CategoryStrip
        ids={["motor", "health", "travel", "home", "business"]}
        onPress={(c) => router.push({ pathname: "/quote/product", params: { product: c.id } })}
      />

      <SectionHeading title={t("exploreFeaturedProviders")} action={t("seeAll")} onAction={() => router.push("/institutions/insurers")} />
      {featured.length ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.featuredRow}>
          {featured.map((p) => (
            <Pressable
              key={p.id}
              accessibilityRole="button"
              accessibilityLabel={p.name}
              onPress={() => router.push({ pathname: "/institutions/insurer/[id]", params: { id: p.id } })}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.featured, pressed && styles.pressed]}
            >
              <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={48} />
              <Text style={styles.featuredName}>{p.name}</Text>
            </Pressable>
          ))}
        </ScrollView>
      ) : null}

      <SectionHeading title={t("explorePopularProducts")} />
      {categories.filter((c) => ["motor", "health", "travel", "home"].includes(c.id)).map((c) => {
        const Icon = c.icon;
        const tint = CATEGORY_TINT[c.id];
        return (
          <Pressable
            key={c.id}
            accessibilityRole="button"
            accessibilityLabel={`${t(c.label)}. ${t(c.caption)}`}
            onPress={() => router.push({ pathname: "/quote/product", params: { product: c.id } })}
            android_ripple={ripple()}
            style={({ pressed }) => [styles.popular, pressed && styles.pressed]}
          >
            <View style={[styles.popularIcon, { backgroundColor: tint.bg }]}>
              <Icon size={34} color={tint.fg} />
            </View>
            <View style={styles.flex}>
              <Text style={styles.popularTitle}>{t(c.label)}</Text>
              <Text style={styles.meta}>{t(c.caption)}</Text>
            </View>
            <View style={styles.chevron}>
              <ChevronRight size={18} color={colors.navy900} />
            </View>
          </Pressable>
        );
      })}

      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${t("compareInsurance")}. ${t("compareInsuranceBody")}`}
        onPress={() => router.push("/quote/product")}
        android_ripple={ripple(true)}
        style={({ pressed }) => [styles.cta, pressed && styles.pressed]}
      >
        <Scale size={26} color={colors.white} />
        <View style={styles.flex}>
          <Text style={styles.ctaTitle}>{t("compareInsurance")}</Text>
          <Text style={styles.ctaBody}>{t("compareInsuranceBody")}</Text>
        </View>
        <ChevronRight size={22} color={colors.white} />
      </Pressable>
      {registerTotals.insurers > 0 ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${t("exploreRegisterEntry", { insurers: registerTotals.insurers, brokers: registerTotals.brokers })}. ${t("exploreRegisterEntryBody")}`}
          onPress={() => router.push("/institutions/insurers")}
          style={({ pressed }) => [styles.register, pressed && styles.pressed]}
        >
          <ShieldCheck size={24} color={colors.navy800} />
          <View style={styles.flex}>
            <Text style={styles.label}>
              {t("exploreRegisterEntry", { insurers: registerTotals.insurers, brokers: registerTotals.brokers })}
            </Text>
            <Text style={styles.meta}>{t("exploreRegisterEntryBody")}</Text>
          </View>
          <ChevronRight size={20} color={colors.neutral500} />
        </Pressable>
      ) : null}
      <Text accessibilityRole="header" style={styles.section}>{t("exploreProviders")}</Text>
      <ChipRow exclusive>
        {(["all", "insurer", "broker"] as Filter[]).map((f) => (
          <Chip
            key={f}
            role="tab"
            label={t(f === "all" ? "filterAll" : f === "insurer" ? "insurers" : "brokers")}
            selected={filter === f}
            onPress={() => setFilter(f)}
          />
        ))}
      </ChipRow>
      {providers.loading && !providers.data ? (
        <LoadingState label={t("exploreLoadingProviders")} />
      ) : providers.error && !providers.data ? (
        <ErrorState onRetry={() => void providers.reload()} />
      ) : shown.length === 0 ? (
        <EmptyState
          title={query || activeFilterCount(extra, sections) ? t("exploreNoResults") : t("exploreNoProviders")}
          message={query || activeFilterCount(extra, sections) ? t("exploreNoResultsBody") : t("exploreNoProvidersBody")}
          action={query || activeFilterCount(extra, sections) ? t("clearSearch") : t("retry")}
          onPress={() => {
            if (query || activeFilterCount(extra, sections)) {
              setQuery("");
              setExtra({ cat: [], prov: [], sort: ["best"] });
            } else void providers.reload();
          }}
        />
      ) : (
        shown.map((p) => (
          <Pressable
            key={p.id}
            accessibilityRole="button"
            accessibilityLabel={`${p.name}. ${t(p.type === "insurer" ? "insurer" : "broker")}`}
            onPress={() =>
              router.push({
                pathname: p.type === "insurer" ? "/institutions/insurer/[id]" : "/institutions/broker/[id]",
                params: { id: p.id },
              })
            }
            style={({ pressed }) => [styles.provider, pressed && styles.pressed]}
          >
            <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={44} />
            <View style={styles.flex}>
              <Text style={styles.label}>{p.name}</Text>
              <Text style={styles.meta}>
                {[p.city, p.products?.length ? (p.products.length === 1 ? t("productsCountOne") : t("productsCount", { count: p.products.length })) : null]
                  .filter(Boolean)
                  .join(" · ")}
              </Text>
            </View>
            <StatusChip label={t(p.type === "insurer" ? "insurer" : "broker")} tone="neutral" />
          </Pressable>
        ))
      )}
      <FiltersSheet
        visible={sheet}
        onClose={() => setSheet(false)}
        sections={sections}
        value={extra}
        onApply={setExtra}
        count={(f) => applyExploreFilters(providers.data ?? [], f).filter((p) => filter === "all" || p.type === filter).length}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  cta: {
    minHeight: 84,
    overflow: "hidden",
    flexDirection: "row",
    alignItems: "center",
    gap: space.x4,
    padding: space.x4,
    borderRadius: radius.feature,
    backgroundColor: colors.blue600,
  },
  register: {
    minHeight: 72,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    padding: space.x4,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: colors.neutral200,
    backgroundColor: colors.white,
  },
  ctaTitle: { ...type.cardTitle, color: colors.white },
  ctaBody: { ...type.meta, color: colors.blue50 },
  featuredRow: { gap: space.x3, paddingVertical: 2 },
  featured: { width: 116, minHeight: 112, alignItems: "center", justifyContent: "center", gap: space.x2, padding: space.x3, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, overflow: "hidden" },
  featuredName: { ...type.label, fontSize: 13, lineHeight: 17, color: colors.navy950, textAlign: "center" },
  popular: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x4, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, overflow: "hidden" },
  popularIcon: { width: 72, height: 72, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  popularTitle: { ...type.cardTitle, color: colors.navy950 },
  chevron: { width: 40, height: 40, borderRadius: 20, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  section: { ...type.cardTitle, color: colors.navy950, marginBottom: -space.x2 },
  category: {
    minHeight: 120,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
    gap: space.x1,
  },
  icon: {
    width: 40,
    height: 40,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: space.x1,
  },
  label: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  provider: {
    minHeight: 72,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x3,
  },
});
