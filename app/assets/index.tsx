import React, { useState } from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Building2, CarFront, ChevronRight, Info, Package, Plus } from "lucide-react-native";
import { Button, Card, Chip, ChipRow, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { normalizeAssetList } from "@/lib/riskAsset";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function Assets() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(async () => normalizeAssetList(await AssetsApi.list()), []);
  const [filter, setFilter] = useState<string | null>(null);
  const types = [...new Set((data ?? []).map((x) => x.type).filter(Boolean))];
  return (
    <Screen scroll={false}>
      <BrandHeader title={t("assetsTitle")} subtitle={t("assetsSubtitle")} back right={null} />
      {types.length > 1 ? (
        <ChipRow exclusive>
          <Chip role="tab" label={t("assetsFilterAll")} selected={!filter} onPress={() => setFilter(null)} />
          {types.map((ty) => (
            <Chip key={ty} role="tab" label={td(`assetType_${ty}`, ty)} selected={filter === ty} onPress={() => setFilter(ty)} />
          ))}
        </ChipRow>
      ) : null}
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        loadingLabel={t("assetsLoading")}
        emptyTitle={t("assetsEmpty")}
        emptyMessage={t("assetsEmptyBody")}
        emptyAction={t("assetsAdd")}
        onEmptyAction={() => router.push("/assets/new")}
      >
        {(items) => (
          <FlatList
            style={s.list}
            data={filter ? items.filter((x) => x.type === filter) : items}
            ListFooterComponent={
              <View style={s.footer}>
                <Button label={t("assetsAdd")} icon={Plus} variant="secondary" onPress={() => router.push("/assets/new")} />
                <Banner icon={Info} tint="neutral" title={t("assetsWhyTitle")} body={t("assetsWhyBody")} />
              </View>
            }
            keyExtractor={(x) => x.id}
            showsVerticalScrollIndicator={false}
            contentContainerStyle={s.content}
            refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
            renderItem={({ item: x }) => {
              const verified = x.status === "VERIFIED" || x.status === "ACTIVE";
              const title = x.label || x.registration_number || t("assetVehicle");
              const sub = [x.make, x.model, x.year].filter(Boolean).join(" · ");
              return (
                <Card style={s.card} accessibilityLabel={[title, sub, td(`status_${x.status}`, x.status)].filter(Boolean).join(", ")} onPress={() => router.push(`/assets/${x.id}`)}>
                  <View style={s.row}>
                    <TintedIcon icon={x.type === "VEHICLE" ? CarFront : x.type === "PROPERTY" ? Building2 : Package} tint="blue" size={48} />
                    <View style={s.flex}>
                      <Text style={s.title}>{title}</Text>
                      {sub ? <Text style={s.sub} numberOfLines={1}>{sub}</Text> : null}
                      {x.label && x.registration_number ? <Text style={s.meta} numberOfLines={1}>{x.registration_number}</Text> : null}
                      {x.type ? (
                        <View style={s.typeChip}>
                          <Text style={s.typeText}>{td(`assetType_${x.type}`, x.type)}</Text>
                        </View>
                      ) : null}
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
  footer: { gap: space.x4 },
  typeChip: { alignSelf: "flex-start", marginTop: space.x2, paddingHorizontal: space.x2, paddingVertical: 2, borderRadius: radius.pill, backgroundColor: colors.blue50 },
  typeText: { ...type.meta, color: colors.blue700, fontWeight: "600" },
  right: { alignItems: "flex-end", gap: space.x2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
});
