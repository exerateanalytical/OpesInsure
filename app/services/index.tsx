import React from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, FileCog, Plus } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { PolicyServicesApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const DONE = ["COMPLETED", "APPROVED", "CLOSED", "RESOLVED"];
const STOPPED = ["REJECTED", "CANCELLED", "DECLINED"];

export default function Services() {
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => PolicyServicesApi.list(), []);
  return (
    <Screen scroll={false}>
      <BrandHeader title={t("svcTitle")} subtitle={t("svcSubtitle")} back right={null} />
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
            showsVerticalScrollIndicator={false}
            contentContainerStyle={s.content}
            refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void reload()} />}
            renderItem={({ item }) => {
              const status = td(`status_${item.status}`, item.status);
              const tone = DONE.includes(item.status) ? "success" : STOPPED.includes(item.status) ? "danger" : "info";
              return (
                <Card
                  style={s.card}
                  accessibilityLabel={[td(`serviceType_${item.type}`, item.type), item.reason, status].filter(Boolean).join(", ")}
                  onPress={() => router.push(`/services/${item.id}`)}
                >
                  <View style={s.row}>
                    <TintedIcon icon={FileCog} tint={tone === "success" ? "green" : tone === "danger" ? "red" : "blue"} size={48} />
                    <View style={s.flex}>
                      <Text style={s.title} numberOfLines={2}>{td(`serviceType_${item.type}`, item.type)}</Text>
                      {item.reason ? <Text style={s.sub} numberOfLines={2}>{item.reason}</Text> : null}
                    </View>
                    <View style={s.right}>
                      <StatusChip label={status} tone={tone} />
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
  sub: { ...type.meta, color: colors.neutral600, marginTop: 2 },
});
