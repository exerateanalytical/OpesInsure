import React, { useEffect, useRef, useState } from "react";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { FileText } from "lucide-react-native";
import { FlowRow } from "@/components/FlowPrimitives";
import { Button, Card, TextField } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { BrokerWorkspaceApi, type ClaimablePolicyRow } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const DEBOUNCE_MS = 300;

/**
 * Broker claim: pick the policy from the WHOLE book (GET mobile/broker/claimable-policies, server-side search by
 * client or policy number, 20 per page with "Show more"). `customerId` narrows to one client (client detail entry).
 * Errors are shown with a retry, never swallowed.
 */
export function ClaimablePolicyPicker({ customerId, onPick }: { customerId?: string; onPick: (p: ClaimablePolicyRow) => void }) {
  const { t } = useTranslation();
  const [query, setQuery] = useState("");
  const [rows, setRows] = useState<ClaimablePolicyRow[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const seq = useRef(0);

  const load = async (q: string, next: number) => {
    const mine = ++seq.current;
    setLoading(true);
    setError(null);
    try {
      const res = await BrokerWorkspaceApi.claimablePolicies({ q: q.trim() || undefined, customer_id: customerId }, next);
      if (mine !== seq.current) return; // a newer search replaced this one
      setRows((prev) => (next === 1 ? res.items : [...prev, ...res.items]));
      setPage(res.info.page);
      setHasMore(res.info.hasMore);
    } catch (e) {
      if (mine === seq.current) setError(e);
    } finally {
      if (mine === seq.current) setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => void load(query, 1), query ? DEBOUNCE_MS : 0);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, customerId]);

  return (
    <Card>
      <Text style={st.label}>{t("brPickPolicy")}</Text>
      <TextField label={t("clmSearchPolicies")} value={query} onChangeText={setQuery} autoCorrect={false} returnKeyType="search" />
      {error ? <ErrorCard error={error} fallback={t("loadErrorBody")} onRetry={() => void load(query, 1)} retryLabel={t("retry")} /> : null}
      {!error && !loading && rows.length === 0 ? <EmptyState title={t("pdNotFound")} message={t("clmNoPolicies")} /> : null}
      <View>
        {rows.map((p) => (
          <FlowRow key={p.id} icon={FileText} title={p.customer_name} subtitle={p.policy_number ?? "—"} onPress={() => onPick(p)} />
        ))}
      </View>
      {loading ? <ActivityIndicator accessibilityLabel={t("loading")} color={colors.blue600} /> : null}
      {!loading && !error && hasMore ? <Button label={t("clmLoadMore")} variant="tertiary" onPress={() => void load(query, page + 1)} /> : null}
    </Card>
  );
}

const st = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
});
