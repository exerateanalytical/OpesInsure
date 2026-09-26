import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, ShieldCheck } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { WalletApi, WalletPolicy } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { policyStatusInfo, type Tone } from "@/lib/purchase";
import { colors, radius, space, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
const toneTint = (tone: Tone): Tint => (tone === "success" ? "green" : tone === "warning" ? "gold" : tone === "danger" ? "red" : tone === "info" ? "blue" : "neutral");

export default function Wallet() {
  const { t } = useTranslation();
  const f = useFormatters();
  const list = usePagedList<WalletPolicy>((page) => WalletApi.list(page));
  return (
    <Screen>
      <BrandHeader title={t("walletTitle")} subtitle={t("walletSubtitle")} back right={null} />
      {list.loading && !list.items.length ? <LoadingState label={t("walletLoading")} /> : null}
      {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("walletLoadFailed")} onRetry={() => void list.reload()} /> : null}
      {!list.loading && !list.error && !list.items.length ? (
        <EmptyState title={t("walletEmpty")} message={t("walletEmptyBody")} action={t("compareInsurance")} onPress={() => router.push("/quote/product")} />
      ) : null}
      {list.items.length ? (
        <View style={s.list}>
          {list.items.map((p) => {
            const status = policyStatusInfo(p.status, f.language);
            const carrier = p.carrier_name ?? p.carrier?.party?.display_name;
            return (
              <Card
                key={p.id}
                style={s.card}
                accessibilityLabel={[p.product_name ?? p.policy_number, carrier, p.policy_number, status.label].filter(Boolean).join(", ")}
                onPress={() => router.push({ pathname: "/wallet/policy/[id]", params: { id: p.id } })}
              >
                <View style={s.row}>
                  <TintedIcon icon={ShieldCheck} tint={toneTint(status.tone)} size={48} />
                  <View style={s.flex}>
                    <Text style={s.title} numberOfLines={2}>{p.product_name ?? p.policy_number}</Text>
                    {carrier ? <Text style={s.sub} numberOfLines={1}>{carrier}</Text> : null}
                    <Text style={s.meta} numberOfLines={1}>{p.policy_number}</Text>
                    <Text style={s.meta} numberOfLines={1}>{f.range(p.coverage_starts_at, p.coverage_ends_at)}</Text>
                  </View>
                  <View style={s.right}>
                    <StatusChip label={status.label} tone={status.tone} />
                    <ChevronRight size={20} color={colors.neutral500} />
                  </View>
                </View>
              </Card>
            );
          })}
        </View>
      ) : null}
      <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  list: { gap: space.x3 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  right: { alignItems: "flex-end", gap: space.x2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
});
