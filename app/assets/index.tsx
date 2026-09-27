import React, { useMemo } from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Building2, CarFront, ChevronRight, Info, Package, Plus } from "lucide-react-native";
import { Button, Card, Chip, ChipRow, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, TintedIcon } from "@/components/design";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { applyFilters, FilterToolbar, optionsFrom, useListFilters, type FilterSection, type FilterValues, type Matchers } from "@/components/filters";
import { normalizeAssetList } from "@/lib/riskAsset";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function Assets() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(async () => normalizeAssetList(await AssetsApi.list()), []);
  const all = useMemo(() => data ?? [], [data]);
  type Asset = (typeof all)[number];
  const types = [...new Set(all.map((x) => x.type).filter(Boolean))];
  // Shared list standard (FLT-001..006): type tabs + sheet (type, status) + search on plate / make / model.
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "type", title: t("fltType"), options: optionsFrom(all, (x) => (x.type ? { value: x.type, label: td(`assetType_${x.type}`, x.type) } : null)) },
      { key: "status", title: t("filterStatus"), options: optionsFrom(all, (x) => (x.status ? { value: x.status, label: td(`status_${x.status}`, x.status) } : null)) },
    ],
    [all, t, td],
  );
  const flt = useListFilters("customer.assets", sections);
  const matchers: Matchers<Asset> = { type: (x, v) => x.type === v, status: (x, v) => x.status === v };
  const haystack = (x: Asset) => [x.label, x.registration_number, x.make, x.model, x.year ? String(x.year) : null];
  const run = (v: FilterValues) => applyFilters(all, v, matchers, flt.query, haystack);
  const sel = flt.values.type ?? [];
  const filter = sel.length === 1 ? sel[0] : null;
  const setFilter = (v: string | null) => flt.setValues({ ...flt.values, type: v ? [v] : [] });
  return (
    <Screen scroll={false}>
      <BrandHeader title={t("assetsTitle")} subtitle={t("assetsSubtitle")} back right={null} />
      {all.length ? (
        <FilterToolbar
          filters={flt}
          sections={sections}
          placeholder={t("fltSearchAssets")}
          count={(v) => run(v).length}
          resultCount={flt.active ? run(flt.values).length : undefined}
          quick={
            types.length ? (
              <ChipRow exclusive>
                <Chip role="tab" label={t("assetsFilterAll")} selected={!sel.length} onPress={() => setFilter(null)} />
                {types.map((ty) => (
                  <Chip key={ty} role="tab" label={td(`assetType_${ty}`, ty)} selected={filter === ty} onPress={() => setFilter(ty)} />
                ))}
              </ChipRow>
            ) : null
          }
        />
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
            data={run(flt.values)}
            ListEmptyComponent={items.length ? <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={flt.clear} /> : null}
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
                      <View style={s.topRow}>
                        <Text style={s.title}>{title}</Text>
                        <StatusChip label={td(`status_${x.status}`, x.status)} tone={verified ? "success" : "warning"} />
                      </View>
                      {sub ? <Text style={s.sub}>{sub}</Text> : null}
                      {x.label && x.registration_number ? <Text style={s.meta}>{x.registration_number}</Text> : null}
                      <View style={s.bottomRow}>
                        {x.type ? (
                          <View style={s.typeChip}>
                            <View style={s.dot} />
                            <Text style={s.typeText}>{td(`assetType_${x.type}`, x.type)}</Text>
                          </View>
                        ) : <View />}
                        <View style={s.view}>
                          <Text style={s.viewText}>{t("docActionView")}</Text>
                          <ChevronRight size={18} color={colors.blue700} />
                        </View>
                      </View>
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
  row: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  footer: { gap: space.x4 },
  dot: { width: 7, height: 7, borderRadius: 4, backgroundColor: colors.blue600 },
  typeChip: { flexDirection: "row", alignItems: "center", gap: 5, alignSelf: "flex-start", paddingHorizontal: space.x2, paddingVertical: 2, borderRadius: radius.pill, backgroundColor: colors.blue50 },
  typeText: { ...type.meta, color: colors.blue700, fontWeight: "600" },
  topRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: space.x2 },
  bottomRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2, marginTop: space.x2 },
  view: { flexDirection: "row", alignItems: "center", gap: 2, minHeight: 32 },
  viewText: { ...type.label, fontSize: 14, color: colors.blue700 },
  title: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950, flexBasis: 120, flexGrow: 1, flexShrink: 1 },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
});
