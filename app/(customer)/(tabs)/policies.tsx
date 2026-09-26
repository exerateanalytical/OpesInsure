import React, { useMemo, useState } from "react";
import { FlatList, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { Button, Screen, SectionTitle, Chip } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { PolicyListCard } from "@/components/policies/PolicyListCard";
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
  const q = query.trim().toLowerCase();
  const visible = (filter === "all" ? policies : policies.filter((p) => policyStatusInfo(p.status).bucket === filter)).filter((p) => {
    if (!q) return true;
    const w = p as { product_name?: string | null; carrier_name?: string | null };
    return [p.policy_number, w.product_name, w.carrier_name].some((x) => (x ?? "").toLowerCase().includes(q));
  });
  const header = (
    <>
      <BrandHeader back={false} title={t("policies")} subtitle={t("policiesTagline")} />
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
            <SearchBar value={query} onChangeText={setQuery} label={t("searchLabel")} placeholder={t("policiesSearchPlaceholder")} clearLabel={t("clearSearch")} />
            <SectionTitle title={t("policiesYours")} />
          </View>
        }
        renderItem={({ item: policy }) => (
          <PolicyListCard policy={policy} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} />
        )}
        ListEmptyComponent={
          <EmptyState title={t("policiesFilterEmpty")} message={t("policiesFilterEmptyBody")} action={t("showAll")} onPress={() => setFilter("all")} />
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
