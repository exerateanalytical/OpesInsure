import React, { useMemo } from "react";
import { FlatList, RefreshControl, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { Button, Screen, SectionTitle } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { PolicyListCard, policyCategory } from "@/components/policies/PolicyListCard";
import { byDate, byText, FilterToolbar, periodMatcher, periodSection, runList, sortSection, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { carrierMark, useCarriers } from "@/components/customer/useCarriers";
import { CATEGORIES } from "@/components/customer/categories";
import type { WalletPolicy } from "@/api/client";
import { usePolicies } from "@/hooks/usePolicies";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { PolicyBucket, policyStatusInfo } from "@/lib/purchase";
import { useListState } from "@/hooks/useListState";
import { useTranslation } from "@/i18n";
import { space } from "@/theme/tokens";

const FILTERS: (PolicyBucket | "all")[] = ["all", "active", "pending", "expired", "cancelled", "suspended"];

export default function Policies() {
  const { t, td } = useTranslation();
  const { policies, loading, error, reload } = usePolicies();
  const carriers = useCarriers();
  const providerOf = (p: WalletPolicy) => {
    const m = carrierMark(carriers, p.carrier_id, { name: p.carrier_name ?? p.carrier?.party?.display_name, logoUrl: (p as { carrier_logo_url?: string | null }).carrier_logo_url });
    return { id: p.carrier_id ?? m.name ?? "", ...m };
  };
  const bucketOf = (p: WalletPolicy) => policyStatusInfo(p.status).bucket;
  // Shared list standard (FLT-001..006): status tabs and the sheet edit the same "status" selection.
  const sections = useMemo<FilterSection[]>(() => {
    const lines = CATEGORIES.filter((c) => policies.some((p) => policyCategory(p)?.id === c.id));
    const provs = new Map<string, ReturnType<typeof providerOf>>();
    policies.forEach((p) => {
      const m = providerOf(p);
      if (m.id && !provs.has(m.id)) provs.set(m.id, m);
    });
    const present = new Set(policies.map(bucketOf));
    return [
      { key: "status", title: t("filterStatus"), subtitle: t("filterStatusBody"), options: FILTERS.filter((k) => k !== "all" && present.has(k)).map((k) => ({ value: k, label: td(`policyFilter_${k}`, k) })) },
      { key: "line", title: t("filterCategory"), subtitle: t("filterCategoryBody"), options: lines.map((c) => ({ value: c.id, label: t(c.label), icon: c.icon })) },
      { key: "provider", title: t("filterProvider"), subtitle: t("filterProviderBody"), options: [...provs.values()].map((m) => ({ value: m.id, label: m.name ?? t("licensedCarrier"), logoUrl: m.logoUrl, initials: m.initials })) },
      periodSection(t, "ends", t("fltPolicyEnds")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "expiry", label: t("fltSortExpiry") },
        { value: "name", label: t("fltSortName") },
      ]),
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [policies, carriers, t, td]);
  const f = useListFilters("customer.policies", sections);
  const matchers: Matchers<WalletPolicy> = {
    status: (p, v) => bucketOf(p) === v,
    line: (p, v) => (policyCategory(p)?.id ?? "") === v,
    provider: (p, v) => providerOf(p).id === v,
    ends: periodMatcher((p) => p.coverage_ends_at),
  };
  const sorters: Sorters<WalletPolicy> = {
    recent: byDate((p) => p.coverage_starts_at),
    expiry: byDate((p) => p.coverage_ends_at, "asc"),
    name: byText((p) => p.product_name ?? p.policy_number),
  };
  const haystack = (p: WalletPolicy) => [p.policy_number, p.product_name, providerOf(p).name, td(`policyFilter_${bucketOf(p)}`, bucketOf(p))];
  const run = (v: FilterValues) => runList(policies, { values: v, text: f.query, matchers, haystack, sorters });
  const visible = run(f.values);
  const listState = useListState("customer.policies");
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
        {...listState}
        data={visible}
        keyExtractor={(p) => p.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={st.content}
        ItemSeparatorComponent={Separator}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
        ListHeaderComponent={
          <View style={st.header}>
            {header}
            <FilterToolbar
              filters={f}
              sections={sections}
              filled
              placeholder={t("policiesSearchPlaceholder")}
              subtitle={t("filtersPoliciesSubtitle")}
              count={(v) => run(v).length}
              resultCount={f.active ? visible.length : undefined}
            />
            <SectionTitle title={t("policiesYours")} />
          </View>
        }
        renderItem={({ item: policy }) => (
          <PolicyListCard policy={policy} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} />
        )}
        ListEmptyComponent={
          <EmptyState title={t("policiesFilterEmpty")} message={t("policiesFilterEmptyBody")} action={t("showAll")} onPress={f.clear} />
        }
        ListFooterComponent={
          <View style={st.footer}>
            <Button label={t("paymentsReceipts")} variant="secondary" onPress={() => router.push("/payments")} />
          </View>
        }
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
