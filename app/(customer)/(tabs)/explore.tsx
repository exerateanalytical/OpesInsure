import React, { useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Building2, ChevronRight, Handshake, Scale } from "lucide-react-native";
import { AppHeader, Screen, StatusChip } from "@/components/ui";
import { SearchBar } from "@/components/SearchBar";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { CATEGORIES } from "@/components/customer/categories";
import { useColumns } from "@/components/responsive";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import type { Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

type Filter = "all" | "insurer" | "broker";

/** Marketplace: search across product categories and licensed providers
 * (GET /public/institutions), with the comparison entry point on top. */
export default function Explore() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{ q?: string }>();
  const [query, setQuery] = useState(typeof params.q === "string" ? params.q : "");
  const [filter, setFilter] = useState<Filter>("all");
  const grid = useColumns({ max: 2, minItem: 140 });
  const providers = useLoad(() => CustomerApi.institutions());

  useEffect(() => {
    if (typeof params.q === "string") setQuery(params.q);
  }, [params.q]);

  const categories = CATEGORIES.filter(
    (c) => c.id !== "more" && matchesQuery(query, t(c.label), t(c.caption), c.id),
  );
  const shown = useMemo(
    () =>
      (providers.data ?? []).filter(
        (p: Institution) =>
          (filter === "all" || p.type === filter) &&
          matchesQuery(query, p.name, p.city, p.code, ...(p.products ?? []).map((x) => `${x.name} ${x.line_code}`)),
      ),
    [providers.data, filter, query],
  );

  return (
    <Screen>
      <AppHeader title={t("explore")} subtitle={t("exploreSubtitle")} />
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${t("compareInsurance")}. ${t("compareInsuranceBody")}`}
        onPress={() => router.push("/quote/product")}
        style={({ pressed }) => [styles.cta, pressed && styles.pressed]}
      >
        <Scale size={26} color={colors.white} />
        <View style={styles.flex}>
          <Text style={styles.ctaTitle}>{t("compareInsurance")}</Text>
          <Text style={styles.ctaBody}>{t("compareInsuranceBody")}</Text>
        </View>
        <ChevronRight size={22} color={colors.white} />
      </Pressable>
      <SearchBar
        value={query}
        onChangeText={setQuery}
        label={t("searchLabel")}
        placeholder={t("searchPlaceholder")}
        clearLabel={t("clearSearch")}
      />

      <Text accessibilityRole="header" style={styles.section}>{t("exploreCategories")}</Text>
      {categories.length ? (
        <View style={grid.row}>
          {categories.map((c) => {
            const Icon = c.icon;
            return (
              <Pressable
                key={c.id}
                accessibilityRole="button"
                accessibilityLabel={`${t(c.label)}. ${t(c.caption)}`}
                onPress={() => router.push({ pathname: "/quote/product", params: { product: c.id } })}
                style={({ pressed }) => [styles.category, grid.item, pressed && styles.pressed]}
              >
                <View style={styles.icon}>
                  <Icon size={22} color={colors.blue600} />
                </View>
                <Text style={styles.label}>{t(c.label)}</Text>
                <Text style={styles.meta}>{t(c.caption)}</Text>
              </Pressable>
            );
          })}
        </View>
      ) : (
        <Text style={styles.meta}>{t("exploreNoCategory")}</Text>
      )}

      <Text accessibilityRole="header" style={styles.section}>{t("exploreProviders")}</Text>
      <View style={styles.filters} accessibilityRole="tablist">
        {(["all", "insurer", "broker"] as Filter[]).map((f) => (
          <Pressable
            key={f}
            accessibilityRole="tab"
            accessibilityState={{ selected: filter === f }}
            onPress={() => setFilter(f)}
            style={[styles.filter, filter === f && styles.filterOn]}
          >
            <Text style={[styles.filterText, filter === f && styles.filterTextOn]}>
              {t(f === "all" ? "filterAll" : f === "insurer" ? "insurers" : "brokers")}
            </Text>
          </Pressable>
        ))}
      </View>
      {providers.loading && !providers.data ? (
        <LoadingState label={t("exploreLoadingProviders")} />
      ) : providers.error && !providers.data ? (
        <ErrorState onRetry={() => void providers.reload()} />
      ) : shown.length === 0 ? (
        <EmptyState
          title={query ? t("exploreNoResults") : t("exploreNoProviders")}
          message={query ? t("exploreNoResultsBody") : t("exploreNoProvidersBody")}
          action={query ? t("clearSearch") : t("retry")}
          onPress={() => (query ? setQuery("") : void providers.reload())}
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
            <View style={styles.initials}>
              {p.type === "insurer" ? (
                <Building2 size={20} color={colors.navy800} />
              ) : (
                <Handshake size={20} color={colors.navy800} />
              )}
            </View>
            <View style={styles.flex}>
              <Text style={styles.label}>{p.name}</Text>
              <Text style={styles.meta}>
                {[p.city, p.products?.length ? t("productsCount", { count: p.products.length }) : null]
                  .filter(Boolean)
                  .join(" · ")}
              </Text>
            </View>
            <StatusChip label={t(p.type === "insurer" ? "insurer" : "broker")} tone="neutral" />
          </Pressable>
        ))
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  cta: {
    minHeight: 84,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x4,
    padding: space.x4,
    borderRadius: radius.feature,
    backgroundColor: colors.blue600,
  },
  ctaTitle: { ...type.cardTitle, color: colors.white },
  ctaBody: { ...type.meta, color: colors.blue50 },
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
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: space.x1,
  },
  label: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  filters: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  filter: {
    minHeight: 44,
    paddingHorizontal: space.x4,
    justifyContent: "center",
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.neutral300,
    backgroundColor: colors.white,
  },
  filterOn: { backgroundColor: colors.navy950, borderColor: colors.navy950 },
  filterText: { ...type.label, color: colors.navy950 },
  filterTextOn: { color: colors.white },
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
  initials: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.neutral100,
    alignItems: "center",
    justifyContent: "center",
  },
});
