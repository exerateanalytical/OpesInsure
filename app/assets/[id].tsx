import React from "react";
import { useLocalSearchParams, router } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { CarFront, ScanLine } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function Asset() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data: a, loading, error, reload } = useLoad(() => AssetsApi.show(id), [id]);
  return (
    <Screen>
      <BrandHeader title={a?.label ?? t("assetVehicle")} back right={null} />
      <StatePanel loading={loading} error={error} data={a} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("assetsLoading")}>
        {(a) => {
          const verified = a.status === "VERIFIED";
          return (
            <Card style={s.card}>
              <View style={s.headRow}>
                <TintedIcon icon={CarFront} tint={verified ? "green" : "gold"} size={56} />
                <View style={s.flex}>
                  <StatusChip label={td(`status_${a.status}`, a.status)} tone={verified ? "success" : "warning"} />
                  <Text style={s.title}>{a.registration_number}</Text>
                  <Text style={s.body}>{[a.make, a.model, a.year].filter(Boolean).join(" · ")}</Text>
                </View>
              </View>
              <Button label={t("assetScanCard")} icon={ScanLine} onPress={() => router.push(`/assets/${id}/scan`)} />
            </Card>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1, gap: space.x1 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950, marginTop: space.x1 },
  body: { ...type.body, color: colors.neutral700 },
});
