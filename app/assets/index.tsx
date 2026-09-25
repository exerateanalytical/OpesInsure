import React from "react";
import { FlatList, RefreshControl, StyleSheet } from "react-native";
import { router } from "expo-router";
import { CarFront, Plus } from "lucide-react-native";
import { AppHeader, Button, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space } from "@/theme/tokens";

export default function Assets() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => AssetsApi.list(), []);
  return (
    <Screen scroll={false}>
      <AppHeader title={t("assetsTitle")} subtitle={t("assetsSubtitle")} back />
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
            refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
            renderItem={({ item: x }) => (
              <FlowRow
                icon={CarFront}
                title={x.label || x.registration_number || t("assetVehicle")}
                subtitle={[x.make, x.model, x.year].filter(Boolean).join(" · ")}
                status={td(`status_${x.status}`, x.status)}
                onPress={() => router.push(`/assets/${x.id}`)}
              />
            )}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  list: { flex: 1, backgroundColor: colors.white, borderRadius: radius.card, paddingHorizontal: space.x4 },
});
