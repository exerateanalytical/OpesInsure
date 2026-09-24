import React, { useMemo, useState } from "react";
import { ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { AppHeader, Button, Screen, SectionTitle } from "@/components/ui";
import { PolicyCard } from "@/components/InsuranceCards";
import { usePolicies } from "@/hooks/usePolicies";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { Pill } from "@/components/purchase/PurchaseUi";
import { PolicyBucket, policyStatusInfo } from "@/lib/purchase";
import { space } from "@/theme/tokens";

const FILTERS: { key: PolicyBucket | "all"; label: string }[] = [
  { key: "all", label: "All" },
  { key: "active", label: "Active" },
  { key: "pending", label: "Pending" },
  { key: "expired", label: "Expired" },
  { key: "cancelled", label: "Cancelled" },
  { key: "suspended", label: "Suspended" },
];

export default function Policies() {
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
  const visible = filter === "all" ? policies : policies.filter((p) => policyStatusInfo(p.status).bucket === filter);
  return (
    <Screen>
      <AppHeader
        title="Policies"
        subtitle="Your active and previous protection"
        action={<Button label="My applications" icon={FileText} variant="tertiary" onPress={() => router.push("/proposals")} />}
      />
      {loading && !policies.length ? (
        <LoadingState label="Loading policies…" />
      ) : error && !policies.length ? (
        <ErrorState onRetry={() => void reload()} />
      ) : policies.length ? (
        <>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.filters}>
            {FILTERS.map((f) => (
              <Pill key={f.key} label={`${f.label}${counts[f.key] ? ` (${counts[f.key]})` : ""}`} selected={filter === f.key} onPress={() => setFilter(f.key)} />
            ))}
          </ScrollView>
          <SectionTitle title="Your policies" />
          {visible.length ? (
            visible.map((policy) => <PolicyCard key={policy.id} policy={policy} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} />)
          ) : (
            <EmptyState title="No policies with this status" message="Choose another filter to see your other policies." action="Show all" onPress={() => setFilter("all")} />
          )}
          <View style={st.gap}>
            <Button label="Payments & receipts" variant="secondary" onPress={() => router.push("/payments")} />
          </View>
        </>
      ) : (
        <EmptyState title="No policies yet" message="Your issued policies will appear here. Applications in progress are under My applications." action="Compare insurance" onPress={() => router.push("/quote/product")} />
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  filters: { gap: space.x2, paddingVertical: space.x1 },
  gap: { gap: space.x2 },
});
