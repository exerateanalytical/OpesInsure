import React from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, CreditCard } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { Payment, PaymentsApi } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { networkName, paymentStatusInfo, type Tone } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const toneTint = (tone: Tone): Tint => (tone === "success" ? "green" : tone === "warning" ? "gold" : tone === "danger" ? "red" : tone === "info" ? "blue" : "neutral");

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
            <BrandHeader title={t("paymentsReceipts")} subtitle={t("paymentsSubtitle")} back right={null} />
            {list.loading && !list.items.length ? <LoadingState label={t("paymentsLoading")} /> : null}
            {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("paymentsLoadFailed")} onRetry={() => void list.reload()} /> : null}
          </View>
        }
        renderItem={({ item: p }) => {
          const status = paymentStatusInfo(p.status, f.language);
          const sub = [networkName(p.provider), p.payer_phone_e164].filter(Boolean).join(" · ");
          const when = p.created_at ? f.date(p.created_at) : null;
          return (
            <Card
              style={s.card}
              accessibilityLabel={[f.xaf(p.amount_minor), sub, when, status.label].filter(Boolean).join(", ")}
              onPress={() => router.push({ pathname: "/payments/[id]", params: { id: p.id } })}
            >
              <View style={s.row}>
                <TintedIcon icon={CreditCard} tint={toneTint(status.tone)} size={48} />
                <View style={s.flex}>
                  <Text style={s.amount}>{f.xaf(p.amount_minor)}</Text>
                  {sub ? <Text style={s.sub} numberOfLines={1}>{sub}</Text> : null}
                  {when ? <Text style={s.meta}>{when}</Text> : null}
                </View>
                <View style={s.right}>
                  <StatusChip label={status.label} tone={status.tone} />
                  <ChevronRight size={20} color={colors.neutral500} />
                </View>
              </View>
            </Card>
          );
        }}
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
  flex: { flex: 1 },
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  footer: { marginTop: space.x4 },
  card: { borderRadius: radius.feature, marginBottom: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  right: { alignItems: "flex-end", gap: space.x2 },
  amount: { fontFamily: "Inter_700Bold", fontSize: 17, lineHeight: 22, color: colors.navy950, fontVariant: ["tabular-nums"] },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
});
