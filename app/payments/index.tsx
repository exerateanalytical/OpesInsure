import React from "react";
import { router } from "expo-router";
import { CreditCard } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { Payment, PaymentsApi } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, paymentStatusInfo } from "@/lib/purchase";

export default function Payments() {
  const f = useFormatters();
  const list = usePagedList<Payment>((page) => PaymentsApi.list(page));
  return (
    <Screen>
      <AppHeader title="Payments & receipts" subtitle="Every transaction, status and recovery action" back />
      {list.loading && !list.items.length ? <LoadingState label="Loading payments…" /> : null}
      {list.error && !list.items.length ? <ErrorCard error={list.error} fallback="Payments could not be loaded." onRetry={() => void list.reload()} /> : null}
      {!list.loading && !list.error && !list.items.length ? (
        <EmptyState title="No payments yet" message="Payments for your insurance applications will appear here." action="Refresh" onPress={() => void list.reload()} />
      ) : null}
      {list.items.length ? (
        <Card>
          {list.items.map((p) => (
            <FlowRow
              key={p.id}
              icon={CreditCard}
              title={f.xaf(p.amount_minor)}
              subtitle={[humanize(p.provider), p.payer_phone_e164, p.created_at ? f.date(p.created_at) : null].filter(Boolean).join(" · ")}
              status={paymentStatusInfo(p.status).label}
              onPress={() => router.push({ pathname: "/payments/[id]", params: { id: p.id } })}
            />
          ))}
        </Card>
      ) : null}
      <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
    </Screen>
  );
}
