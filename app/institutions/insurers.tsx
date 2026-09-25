import React, { useMemo, useState } from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight } from "lucide-react-native";
import { AppHeader, Button, Card, Chip, ChipRow, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
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
  const [query, setQuery] = useState("");
  const [branch, setBranch] = useState<BranchFilter>("all");
  const q = useLoad(() => InstitutionsApi.list("insurer"), []);
  const counts = useMemo(() => registerCounts(q.data ?? []), [q.data]);
  const [city, setCity] = useState<string | null>(null);
  const cities = useMemo(() => directoryCities(q.data ?? []), [q.data]);
  const filtered = useMemo(
    () => filterByCity(filterInsurers(q.data ?? [], branch, query), city),
    [q.data, branch, query, city],
  );

  return (
    <Screen>
      <AppHeader
        title={t("licensedInsurers", { count: counts.total || (q.data?.length ?? 0) })}
        subtitle={t(REGISTER_SOURCE_KEY)}
        back
      />
      <ChipRow exclusive>
        {BRANCHES.map((b) => {
          const n = b.id === "all" ? counts.total : counts[b.id];
          return (
            <Chip
              key={b.id}
              role="tab"
              label={`${t(b.label)}${n ? ` (${n})` : ""}`}
              selected={branch === b.id}
              onPress={() => setBranch(b.id)}
            />
          );
        })}
      </ChipRow>
      <TextField
        label={t("searchInsurers")}
        value={query}
        onChangeText={setQuery}
        placeholder={t("searchInsurersPlaceholder")}
      />
      {cities.length > 1 ? (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          accessibilityLabel={t("filterByCity")}
          contentContainerStyle={styles.tabs}
        >
          {[null, ...cities].map((c) => (
            <Chip key={c ?? "all"} label={c ?? t("cityAll")} selected={city === c} onPress={() => setCity(c)} />
          ))}
        </ScrollView>
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
          <View style={styles.logo}>
            <Text style={styles.logoText}>{insurer.initials}</Text>
          </View>
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
  tabs: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  copy: { flex: 1, gap: 3 },
  logo: {
    width: 42,
    height: 42,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
  },
  logoText: { ...type.caption, color: colors.blue700 },
  name: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    backgroundColor: colors.neutral100,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  products: { ...type.meta, color: colors.successText },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
