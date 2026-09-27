import React, { useMemo } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandArt } from "@/components/design/BrandArt";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { FilterToolbar, useListFilters, type FilterSection } from "@/components/filters";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import {
  REGISTER_SOURCE_KEY,
  directoryCities,
  filterByCity,
  filterInsurers,
  readDirectory,
  registerCounts,
  verificationText,
  type BranchFilter,
} from "@/lib/institutions";
import { colors, radius, space, type } from "@/theme/tokens";

const BRANCHES: { id: BranchFilter; label: "branchAll" | "branchIARD" | "branchLIFE" }[] = [
  { id: "all", label: "branchAll" },
  { id: "IARD", label: "branchIARD" },
  { id: "LIFE", label: "branchLIFE" },
];

/** Licensed insurers from the DGTCFM/MINFI 2026 register, split Non-life (IARD) / Life. */
export default function Insurers() {
  const { t } = useTranslation();
  const q = useLoad(() => InstitutionsApi.list("insurer"), []);
  const counts = useMemo(() => registerCounts(q.data ?? []), [q.data]);
  const cities = useMemo(() => directoryCities(q.data ?? []), [q.data]);
  // One filtering control per page: branch (Non-life / Life) and city tabs moved into the filter sheet.
  const sections = useMemo<FilterSection[]>(
    () => [
      {
        key: "branch",
        single: true,
        title: t("filterBranch"),
        options: BRANCHES.map((b) => {
          const n = b.id === "all" ? counts.total : counts[b.id];
          return { value: b.id, label: `${t(b.label)}${n ? ` (${n})` : ""}` };
        }),
      },
      ...(cities.length > 1
        ? [{ key: "city", single: true, title: t("filterByCity"), options: [{ value: "all", label: t("cityAll") }, ...cities.map((c) => ({ value: c, label: c }))] }]
        : []),
    ],
    [cities, counts, t],
  );
  // Shared list memory (FLT/NAV-002): branch, city and search survive back / tab switches.
  const flt = useListFilters("customer.insurers", sections);
  const branch = (flt.values.branch?.[0] ?? "all") as BranchFilter;
  const cityValue = flt.values.city?.[0];
  const city = cityValue && cityValue !== "all" ? cityValue : null;
  const filtered = useMemo(
    () => filterByCity(filterInsurers(q.data ?? [], branch, flt.query), city),
    [q.data, branch, flt.query, city],
  );

  return (
    <Screen>
      <AppHeader
        title={t("licensedInsurers", { count: counts.total || (q.data?.length ?? 0) })}
        subtitle={t(REGISTER_SOURCE_KEY)}
        back
      />
      <FilterToolbar
        filters={flt}
        sections={sections}
        placeholder={t("searchInsurersPlaceholder")}
        count={(v) => filterByCity(filterInsurers(q.data ?? [], (v.branch?.[0] ?? "all") as BranchFilter, flt.query), v.city?.[0] && v.city[0] !== "all" ? v.city[0] : null).length}
      />
      {q.data && (flt.query || branch !== "all" || city) ? (
        <Text accessibilityLiveRegion="polite" style={styles.results}>
          {t(filtered.length === 1 ? "fltResultsOne" : "fltResults", { count: filtered.length })}
        </Text>
      ) : null}
      <Button
        label={t("browseAuthorizedBrokers")}
        variant="secondary"
        onPress={() => router.push("/institutions/brokers")}
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("loadingInsurers")}
        emptyTitle={t("noInsurersFound")}
        emptyMessage={t("registerEmptyBody")}
      >
        {() =>
          filtered.length ? (
            <>
              {filtered.map((i) => (
                <InsurerRow key={i.id} insurer={i} />
              ))}
            </>
          ) : (
            <Card>
              <Text style={styles.name}>{t("noInsurersFound")}</Text>
            </Card>
          )
        }
      </StatePanel>
      <BrandArt name="africa_dots_gold" width={88} opacity={0.8} />
      <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
    </Screen>
  );
}

function InsurerRow({ insurer }: { insurer: Institution }) {
  const { t, language } = useTranslation();
  const families = insurer.product_families ?? [];
  const dir = readDirectory(insurer);
  const hqCity = dir.hq?.city ?? insurer.city;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${insurer.short_name ?? insurer.name}. ${insurer.name}`}
      onPress={() => router.push({ pathname: "/institutions/insurer/[id]", params: { id: insurer.id } })}
    >
      <Card>
        <View style={styles.row}>
          <InstitutionMark logoUrl={institutionLogo(insurer)} initials={insurer.initials} />
          <View style={styles.copy}>
            <Text style={styles.name}>{insurer.short_name ?? insurer.name}</Text>
            {insurer.short_name ? <Text style={styles.meta}>{insurer.name}</Text> : null}
            {hqCity || dir.branches.length ? (
              <Text style={styles.meta}>
                {[hqCity, dir.branches.length ? t("branchNetwork", { count: dir.branches.length }) : null]
                  .filter(Boolean)
                  .join(" · ")}
              </Text>
            ) : null}
          </View>
          {insurer.branch ? (
            <StatusChip
              label={t(insurer.branch === "LIFE" ? "branchBadgeLIFE" : "branchBadgeIARD")}
              tone={insurer.branch === "LIFE" ? "success" : "info"}
            />
          ) : null}
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
        {dir.verification ? (
          <View style={styles.families}>
            <StatusChip label={verificationText(dir.verification, language, t)} tone={dir.verification.tone} />
          </View>
        ) : null}
        {families.length ? (
          <View style={styles.families}>
            {families.map((f) => (
              <Text key={f} style={styles.family}>{f}</Text>
            ))}
          </View>
        ) : null}
        {insurer.products?.length ? (
          <Text style={styles.products}>{t("productsCount", { count: insurer.products.length })} · OpesInsure</Text>
        ) : null}
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  results: { ...type.meta, color: colors.neutral600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  copy: { flex: 1, gap: 3 },
  name: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  products: { ...type.meta, color: colors.successText },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
