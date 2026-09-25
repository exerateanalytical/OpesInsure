import React, { useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Building2, ChevronRight, Handshake, Scale, ShieldCheck } from "lucide-react-native";
import { AppHeader, Chip, ChipRow, Screen, StatusChip } from "@/components/ui";
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
  // Official DGTCFM/MINFI register counts (29 insurers / 123 brokers when seeded).
  const registerTotals = useMemo(() => {
    const official = (providers.data ?? []).filter((p: Institution) => p.is_official_register);
    return {
      insurers: official.filter((p) => p.type === "insurer").length,
      brokers: official.filter((p) => p.type === "broker").length,
    };
  }, [providers.data]);
  const shown = useMemo(
    () =>
      (providers.data ?? []).filter(
        (p: Institution) =>
          (filter === "all" || p.type === filter) &&
          matchesQuery(query, p.name, p.short_name, p.city, p.code, ...(p.products ?? []).map((x) => `${x.name} ${x.line_code}`)),
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
                  <Icon size={30} color={colors.blue600} />
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
  initials: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: "center",
    justifyContent: "center",
  },
});
