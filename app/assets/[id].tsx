import React from "react";
import { useLocalSearchParams, router } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { AssetsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Asset() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data: a, loading, error, reload } = useLoad(() => AssetsApi.show(id), [id]);
  return (
    <Screen>
      <AppHeader title={a?.label ?? t("assetVehicle")} back />
      <StatePanel loading={loading} error={error} data={a} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("assetsLoading")}>
        {(a) => (
          <Card>
            <StatusChip label={td(`status_${a.status}`, a.status)} tone={a.status === "VERIFIED" ? "success" : "warning"} />
            <Text style={s.title}>{a.registration_number}</Text>
            <Text style={s.body}>{[a.make, a.model, a.year].filter(Boolean).join(" · ")}</Text>
            <Button label={t("assetScanCard")} onPress={() => router.push(`/assets/${id}/scan`)} />
          </Card>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});
