import React from "react";
import { router } from "expo-router";
import { ShieldCheck } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { WalletApi, WalletPolicy } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { policyStatusInfo } from "@/lib/purchase";

import { useTranslation } from "@/i18n";
export default function Wallet() {
  const { t } = useTranslation();
  const f = useFormatters();
  const list = usePagedList<WalletPolicy>((page) => WalletApi.list(page));
  return (
    <Screen>
      <AppHeader title={t("walletTitle")} subtitle={t("walletSubtitle")} back />
      {list.loading && !list.items.length ? <LoadingState label={t("walletLoading")} /> : null}
      {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("walletLoadFailed")} onRetry={() => void list.reload()} /> : null}
      {!list.loading && !list.error && !list.items.length ? (
        <EmptyState title={t("walletEmpty")} message={t("walletEmptyBody")} action={t("compareInsurance")} onPress={() => router.push("/quote/product")} />
      ) : null}
      {list.items.length ? (
        <Card>
          {list.items.map((p) => (
            <FlowRow
              key={p.id}
              icon={ShieldCheck}
              title={p.product_name ?? p.policy_number}
              subtitle={[p.carrier_name ?? p.carrier?.party?.display_name, p.policy_number, f.range(p.coverage_starts_at, p.coverage_ends_at)].filter(Boolean).join(" · ")}
              status={policyStatusInfo(p.status, f.language).label}
              onPress={() => router.push({ pathname: "/wallet/policy/[id]", params: { id: p.id } })}
            />
          ))}
        </Card>
      ) : null}
      <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
    </Screen>
  );
}
