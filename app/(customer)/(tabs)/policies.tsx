import React, { useMemo, useState } from "react";
import { FlatList, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { Button, Screen, SectionTitle, Chip } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { PolicyListCard, policyCategory } from "@/components/policies/PolicyListCard";
import { activeFilterCount, FiltersSheet, type FilterSection, type FilterValues } from "@/components/customer/FiltersSheet";
import { carrierMark, useCarriers } from "@/components/customer/useCarriers";
import { CATEGORIES } from "@/components/customer/categories";
import type { WalletPolicy } from "@/api/client";
import { usePolicies } from "@/hooks/usePolicies";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { PolicyBucket, policyStatusInfo } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { space } from "@/theme/tokens";

const FILTERS: (PolicyBucket | "all")[] = ["all", "active", "pending", "expired", "cancelled", "suspended"];

export default function Policies() {
  const { t, td } = useTranslation();
  const { policies, loading, error, reload } = usePolicies();
  const [filter, setFilter] = useState<PolicyBucket | "all">("all");
  const counts = useMemo(() => {
    const c: Record<string, number> = { all: policies.length };
    policies.forEach((p) => {
      const b = policyStatusInfo(p.status).bucket;
      c[b] = (c[b] ?? 0) + 1;
    });
    return c;
  }, [policies]);
  const [query, setQuery] = useState("");
  const [sheet, setSheet] = useState(false);
  const [extra, setExtra] = useState<FilterValues>({});
  const carriers = useCarriers();
  const providerOf = (p: WalletPolicy) => {
    const m = carrierMark(carriers, p.carrier_id, { name: p.carrier_name ?? p.carrier?.party?.display_name, logoUrl: (p as { carrier_logo_url?: string | null }).carrier_logo_url });
    return { id: p.carrier_id ?? m.name ?? "", ...m };
  };
  // Filter sheet: only the dimensions the wallet payload carries (status, product line, insurer).
  const sections = useMemo<FilterSection[]>(() => {
    const lines = CATEGORIES.filter((c) => policies.some((p) => policyCategory(p)?.id === c.id));
    const provs = new Map<string, ReturnType<typeof providerOf>>();
    policies.forEach((p) => {
      const m = providerOf(p);
      if (m.id && !provs.has(m.id)) provs.set(m.id, m);
    });
    return [
      { key: "status", title: t("filterStatus"), subtitle: t("filterStatusBody"), options: FILTERS.filter((k) => k !== "all" && counts[k]).map((k) => ({ value: k, label: td(`policyFilter_${k}`, k) })) },
      { key: "line", title: t("filterCategory"), subtitle: t("filterCategoryBody"), options: lines.map((c) => ({ value: c.id, label: t(c.label), icon: c.icon })) },
      { key: "provider", title: t("filterProvider"), subtitle: t("filterProviderBody"), options: [...provs.values()].map((m) => ({ value: m.id, label: m.name ?? t("licensedCarrier"), logoUrl: m.logoUrl, initials: m.initials })) },
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [policies, carriers, counts, t, td]);
  const apply = (list: WalletPolicy[], f: FilterValues, bucket: PolicyBucket | "all", text: string) => {
    const q = text.trim().toLowerCase();
    return list.filter((p) => {
      const b = policyStatusInfo(p.status).bucket;
      if (bucket !== "all" && b !== bucket) return false;
      if (f.status?.length && !f.status.includes(b)) return false;
      if (f.line?.length && !f.line.includes(policyCategory(p)?.id ?? "")) return false;
      const m = providerOf(p);
      if (f.provider?.length && !f.provider.includes(m.id)) return false;
      if (!q) return true;
      return [p.policy_number, p.product_name, m.name].some((x) => (x ?? "").toLowerCase().includes(q));
    });
  };
  const visible = apply(policies, extra, filter, query);
  const header = (
    <>
      <BrandHeader back={false} title={t("myPoliciesTitle")} subtitle={t("policiesTagline")} />
      <Button label={t("myApplications")} icon={FileText} variant="tertiary" onPress={() => router.push("/proposals")} />
    </>
  );
  if (!policies.length)
    return (
      <Screen>
        {header}
        {loading ? (
          <LoadingState label={t("policiesLoading")} />
        ) : error ? (
          <ErrorState error={error} onRetry={() => void reload()} />
        ) : (
          <EmptyState title={t("policiesEmpty")} message={t("policiesEmptyBody")} action={t("compareInsurance")} onPress={() => router.push("/quote/product")} />
        )}
      </Screen>
    );
  // Virtualized: a long policy history no longer renders every card at once.
  return (
    <Screen scroll={false}>
      <FlatList
        data={visible}
        keyExtractor={(p) => p.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={st.content}
        ItemSeparatorComponent={Separator}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
        ListHeaderComponent={
          <View style={st.header}>
            {header}
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.filters}>
              {FILTERS.filter((key) => key === "all" || counts[key]).map((key) => {
                const label = key === "all" ? t("filterAll") : td(`policyFilter_${key}`, key);
                return <Chip key={key} label={`${label}${counts[key] ? ` (${counts[key]})` : ""}`} selected={filter === key} onPress={() => setFilter(key)} />;
              })}
            </ScrollView>
            <SearchBar
              value={query}
              onChangeText={setQuery}
              label={t("searchLabel")}
              placeholder={t("policiesSearchPlaceholder")}
              clearLabel={t("clearSearch")}
              onFilter={() => setSheet(true)}
              filterLabel={t("filtersTitle")}
              filterCount={activeFilterCount(extra, sections)}
            />
            <SectionTitle title={t("policiesYours")} />
          </View>
        }
        renderItem={({ item: policy }) => (
          <PolicyListCard policy={policy} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} />
        )}
        ListEmptyComponent={
          <EmptyState title={t("policiesFilterEmpty")} message={t("policiesFilterEmptyBody")} action={t("showAll")} onPress={() => { setFilter("all"); setExtra({}); setQuery(""); }} />
        }
        ListFooterComponent={
          <View style={st.footer}>
            <Button label={t("paymentsReceipts")} variant="secondary" onPress={() => router.push("/payments")} />
          </View>
        }
      />
      <FiltersSheet
        visible={sheet}
        onClose={() => setSheet(false)}
        sections={sections}
        value={extra}
        onApply={setExtra}
        count={(f) => apply(policies, f, filter, query).length}
        subtitle={t("filtersPoliciesSubtitle")}
      />
    </Screen>
  );
}

const Separator = () => <View style={st.sep} />;

const st = StyleSheet.create({
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  filters: { gap: space.x2, paddingVertical: space.x1 },
  sep: { height: space.x3 },
  footer: { gap: space.x2, marginTop: space.x6 },
});
