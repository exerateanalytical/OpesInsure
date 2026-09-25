import React, { useMemo, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { RefreshCcw } from "lucide-react-native";
import { AppHeader, Button, Screen } from "@/components/ui";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { CompareTable, CompareTableRow } from "@/components/offers/CompareTable";
import { QuoteWorkflowApi } from "@/api/workflow";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { comparisonRows, QuoteComparison } from "@/lib/quoteWorkflow";

/** Latest saved comparison for the quote that is still fresh, else a new one (POST quote-comparisons). */
async function currentComparison(quoteId: string): Promise<QuoteComparison> {
  const saved = await QuoteWorkflowApi.comparisons(quoteId).catch(() => [] as QuoteComparison[]);
  const fresh = saved.find((c) => !c.is_expired);
  return fresh ?? QuoteWorkflowApi.compare(quoteId);
}

/** REQ-DST-003 comparison: premium, tax, fees, total, then limits, deductibles and exclusions per offer. */
export default function QuoteComparisonScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => currentComparison(id), [id]);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const c = q.data;
  const offers = c?.offers ?? [];

  const rows = useMemo<CompareTableRow[]>(() => {
    if (!c) return [];
    const raw = comparisonRows(c, f.language, { included: t("cmpIncluded"), notIncluded: t("cmpNotIncluded"), applies: t("cmpApplies"), none: t("cmpNone") });
    const out: CompareTableRow[] = [];
    let section: string | undefined;
    for (const r of raw) {
      if (r.section && r.section !== section && r.section !== "price") out.push({ key: `section:${r.section}`, label: t(`cmpSection_${r.section}`), heading: true, cells: [] });
      section = r.section;
      // Price rows carry i18n keys; coverage/exclusion rows carry their own names.
      out.push({ ...r, label: r.section === "price" || r.key === "insurer" ? td(r.label, r.label) : r.label });
    }
    return out;
  }, [c, f.language, t, td]);

  const refresh = async () => {
    setRefreshing(true);
    setError(null);
    try {
      q.setData(await QuoteWorkflowApi.compare(id));
    } catch (e) {
      setError(e);
    } finally {
      setRefreshing(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("qwCompareTitle")} subtitle={offers.length ? t("qwCompareSubtitle", { count: offers.length }) : undefined} back />
      {q.loading && !c ? <LoadingState label={t("qwCompareLoading")} /> : null}
      {q.error && !c ? <ErrorCard error={q.error} fallback={t("qwCompareFailed")} onRetry={() => void q.reload()} /> : null}
      {c && offers.length < 2 ? <EmptyState title={t("qwCompareNeedTwo")} message={t("qwCompareFailed")} /> : null}
      {c && offers.length >= 2 ? <CompareTable rows={rows} columns={offers.length} money={f.xaf} /> : null}
      {c?.is_expired ? <Text style={ps.error}>{t("qwCompareExpired")}</Text> : null}
      {error ? <ErrorCard error={error} fallback={t("qwCompareFailed")} /> : null}
      {c ? <Button label={t("qwCompareRefresh")} icon={RefreshCcw} variant="secondary" loading={refreshing} disabled={refreshing} onPress={() => void refresh()} /> : null}
    </Screen>
  );
}
