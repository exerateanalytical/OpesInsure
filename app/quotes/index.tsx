import React from "react";
import { FlatList, RefreshControl, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Clock3 } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { CustomerQuoteSummary, QuotesApi } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space } from "@/theme/tokens";

export default function QuoteHistory() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const list = usePagedList<CustomerQuoteSummary>((page) => QuotesApi.history(page));
  return (
    <Screen scroll={false}>
      <FlatList
        data={list.items}
        keyExtractor={(q) => q.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={s.content}
        refreshControl={<RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.moreError) void list.loadMore();
        }}
        ListHeaderComponent={
          <View style={s.header}>
            <AppHeader title={t("quotesTitle")} subtitle={t("quotesSubtitle")} back />
            {list.loading && !list.items.length ? <LoadingState label={t("quotesLoading")} /> : null}
            {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("quotesLoadFailed")} onRetry={() => void list.reload()} /> : null}
          </View>
        }
        renderItem={({ item: q, index }) => (
          <View style={[s.row, index === 0 && s.first, index === list.items.length - 1 && s.last]}>
            <FlowRow
              icon={Clock3}
              title={q.product_name ?? humanize(q.line_code)}
              subtitle={[
                q.vehicle_label,
                q.offer_count != null ? t("quotesOfferCount", { count: q.offer_count }) : null,
                q.expires_at ? t("quotesValidUntil", { date: f.date(q.expires_at) }) : null,
              ]
                .filter(Boolean)
                .join(" · ")}
              status={td(`quoteStatus_${q.status}`, q.status)}
              onPress={() => router.push({ pathname: "/quotes/[id]", params: { id: q.id } })}
            />
          </View>
        )}
        ListEmptyComponent={
          !list.loading && !list.error ? (
            <EmptyState title={t("quotesEmpty")} message={t("quotesEmptyBody")} action={t("quotesGetQuote")} onPress={() => router.push("/quote/product")} />
          ) : null
        }
        ListFooterComponent={
          <View style={s.footer}>
            <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
          </View>
        }
      />
    </Screen>
  );
}
const s = StyleSheet.create({
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  row: { backgroundColor: colors.white, paddingHorizontal: space.x4 },
  first: { borderTopLeftRadius: radius.card, borderTopRightRadius: radius.card },
  last: { borderBottomLeftRadius: radius.card, borderBottomRightRadius: radius.card },
  footer: { marginTop: space.x4 },
});
