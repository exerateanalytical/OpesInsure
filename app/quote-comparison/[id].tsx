import React from "react";
import { Redirect, useLocalSearchParams } from "expo-router";
import { CircleAlert } from "lucide-react-native";
import { Banner, BrandHeader } from "@/components/design";
import { Screen } from "@/components/ui";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { useNow } from "@/components/offers/OfferCard";
import { QuoteComparisonView } from "@/components/offers/QuoteComparisonView";
import { QuoteWorkflowApi } from "@/api/workflow";
import { roleToPortal, useSession } from "@/store/session";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import type { QuoteComparison } from "@/lib/quoteWorkflow";

/** Latest saved comparison for the quote that is still fresh, else a new one (POST quote-comparisons). */
async function currentComparison(quoteId: string): Promise<QuoteComparison> {
  const saved = await QuoteWorkflowApi.comparisons(quoteId).catch(() => [] as QuoteComparison[]);
  const fresh = saved.find((c) => !c.is_expired);
  return fresh ?? QuoteWorkflowApi.compare(quoteId);
}

/**
 * Old comparison link (quotes list, quote detail, notifications). Customers are redirected to the
 * one comparison screen (quote/compare), which can also choose an offer; agents and brokers see the
 * same comparison card read-only (they do not accept offers for the customer here).
 */
export default function QuoteComparisonRoute() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const customer = useSession((s) => roleToPortal(s.activeWorkspace?.role_code) === "customer");
  if (customer) return <Redirect href={{ pathname: "/quote/compare", params: { quoteId: id } }} />;
  return <PartnerComparison id={id} />;
}

function PartnerComparison({ id }: { id: string }) {
  const { t } = useTranslation();
  const now = useNow();
  const q = useLoad(() => currentComparison(id), [id]);
  const c = q.data;
  const count = c?.offers?.length ?? 0;
  return (
    <Screen>
      <BrandHeader title={t("qwCompareTitle")} subtitle={count ? t("qwCompareSubtitle", { count }) : undefined} />
      {q.loading && !c ? <LoadingState label={t("qwCompareLoading")} /> : null}
      {q.error && !c ? <ErrorCard error={q.error} fallback={t("qwCompareFailed")} onRetry={() => void q.reload()} /> : null}
      {c && count < 2 ? <EmptyState title={t("qwCompareNeedTwo")} message={t("qwCompareFailed")} /> : null}
      {c?.is_expired ? <Banner icon={CircleAlert} tint="red" body={t("qwCompareExpired")} /> : null}
      {c && count >= 2 ? <QuoteComparisonView comparison={c} now={now} /> : null}
    </Screen>
  );
}
