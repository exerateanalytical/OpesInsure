import React from "react";
import { FlatList, RefreshControl, StyleSheet } from "react-native";
import { router } from "expo-router";
import { FileCog, Plus } from "lucide-react-native";
import { AppHeader, Button, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { PolicyServicesApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space } from "@/theme/tokens";

export default function Services() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => PolicyServicesApi.list(), []);
  return (
    <Screen scroll={false}>
      <AppHeader title={t("svcTitle")} subtitle={t("svcSubtitle")} back />
      <Button label={t("svcNew")} icon={Plus} onPress={() => router.push("/services/new")} />
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        loadingLabel={t("svcLoading")}
        emptyTitle={t("svcEmpty")}
        emptyMessage={t("svcEmptyBody")}
      >
        {(items) => (
          <FlatList
            style={s.list}
            data={items}
            keyExtractor={(x) => x.id}
            refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
            renderItem={({ item }) => (
              <FlowRow
                icon={FileCog}
                title={td(`serviceType_${item.type}`, item.type)}
                subtitle={item.reason}
                status={td(`status_${item.status}`, item.status)}
                onPress={() => router.push(`/services/${item.id}`)}
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
