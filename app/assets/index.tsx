import React from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CarFront, ChevronRight, Plus } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function Assets() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => AssetsApi.list(), []);
  return (
    <Screen scroll={false}>
      <BrandHeader title={t("assetsTitle")} subtitle={t("assetsSubtitle")} back right={null} />
      <Button label={t("assetsAdd")} icon={Plus} onPress={() => router.push("/assets/new")} />
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        loadingLabel={t("assetsLoading")}
        emptyTitle={t("assetsEmpty")}
        emptyMessage={t("assetsEmptyBody")}
      >
        {(items) => (
          <FlatList
            style={s.list}
            data={items}
            keyExtractor={(x) => x.id}
            showsVerticalScrollIndicator={false}
            contentContainerStyle={s.content}
            refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
            renderItem={({ item: x }) => {
              const verified = x.status === "VERIFIED";
              const title = x.label || x.registration_number || t("assetVehicle");
              const sub = [x.make, x.model, x.year].filter(Boolean).join(" · ");
              return (
                <Card style={s.card} accessibilityLabel={[title, sub, td(`status_${x.status}`, x.status)].filter(Boolean).join(", ")} onPress={() => router.push(`/assets/${x.id}`)}>
                  <View style={s.row}>
                    <TintedIcon icon={CarFront} tint={verified ? "green" : "gold"} size={48} />
                    <View style={s.flex}>
                      <Text style={s.title} numberOfLines={1}>{title}</Text>
                      {sub ? <Text style={s.sub} numberOfLines={1}>{sub}</Text> : null}
                      {x.label && x.registration_number ? <Text style={s.meta} numberOfLines={1}>{x.registration_number}</Text> : null}
                    </View>
                    <View style={s.right}>
                      <StatusChip label={td(`status_${x.status}`, x.status)} tone={verified ? "success" : "warning"} />
                      <ChevronRight size={20} color={colors.neutral500} />
                    </View>
                  </View>
                </Card>
              );
            }}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  list: { flex: 1 },
  content: { paddingBottom: space.x16 },
  card: { borderRadius: radius.feature, marginBottom: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  right: { alignItems: "flex-end", gap: space.x2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
});
