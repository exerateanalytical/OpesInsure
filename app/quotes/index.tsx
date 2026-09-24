import React from "react";
import { router } from "expo-router";
import { Clock3 } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { CustomerQuoteSummary, QuotesApi } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize } from "@/lib/purchase";

export default function QuoteHistory() {
  const f = useFormatters();
  const list = usePagedList<CustomerQuoteSummary>((page) => QuotesApi.history(page));
  return (
    <Screen>
      <AppHeader title="Saved quotes" subtitle="Resume valid quotes or review previous comparisons" back />
      {list.loading && !list.items.length ? <LoadingState label="Loading quotes…" /> : null}
      {list.error && !list.items.length ? <ErrorCard error={list.error} fallback="Saved quotes could not be loaded." onRetry={() => void list.reload()} /> : null}
      {!list.loading && !list.error && !list.items.length ? (
        <EmptyState title="No saved quotes" message="Quotes you request are kept here so you can come back to them." action="Get a quote" onPress={() => router.push("/quote/product")} />
      ) : null}
      {list.items.length ? (
        <Card>
          {list.items.map((q) => (
            <FlowRow
              key={q.id}
              icon={Clock3}
              title={q.product_name ?? humanize(q.line_code)}
              subtitle={[q.vehicle_label, q.offer_count != null ? `${q.offer_count} offers` : null, q.expires_at ? `valid until ${f.date(q.expires_at)}` : null].filter(Boolean).join(" · ")}
              status={humanize(q.status)}
              onPress={() => router.push({ pathname: "/quotes/[id]", params: { id: q.id } })}
            />
          ))}
        </Card>
      ) : null}
      <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
    </Screen>
  );
}
