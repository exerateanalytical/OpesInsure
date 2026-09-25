import React from "react";
import { FlatList, RefreshControl, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { CreditCard } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { Payment, PaymentsApi } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, paymentStatusInfo } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space } from "@/theme/tokens";

export default function Payments() {
  const { t } = useTranslation();
  const f = useFormatters();
  const list = usePagedList<Payment>((page) => PaymentsApi.list(page));
  return (
    <Screen scroll={false}>
      <FlatList
        data={list.items}
        keyExtractor={(p) => p.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={s.content}
        refreshControl={<RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.moreError) void list.loadMore();
        }}
        ListHeaderComponent={
          <View style={s.header}>
            <AppHeader title={t("paymentsReceipts")} subtitle={t("paymentsSubtitle")} back />
            {list.loading && !list.items.length ? <LoadingState label={t("paymentsLoading")} /> : null}
            {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("paymentsLoadFailed")} onRetry={() => void list.reload()} /> : null}
          </View>
        }
        renderItem={({ item: p, index }) => (
          <View style={[s.row, index === 0 && s.first, index === list.items.length - 1 && s.last]}>
            <FlowRow
              icon={CreditCard}
              title={f.xaf(p.amount_minor)}
              subtitle={[humanize(p.provider), p.payer_phone_e164, p.created_at ? f.date(p.created_at) : null].filter(Boolean).join(" · ")}
              status={paymentStatusInfo(p.status).label}
              onPress={() => router.push({ pathname: "/payments/[id]", params: { id: p.id } })}
            />
          </View>
        )}
        ListEmptyComponent={
          !list.loading && !list.error ? (
            <EmptyState title={t("paymentsEmpty")} message={t("paymentsEmptyBody")} action={t("refresh")} onPress={() => void list.reload()} />
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
