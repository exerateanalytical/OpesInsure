import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { SyncSettings } from "@/offline/types";
import { useResilience } from "@/store/resilience";
import { colors, space, type } from "@/theme/tokens";

function Setting({ label, hint, value, onValueChange }: { label: string; hint: string; value: boolean; onValueChange: (value: boolean) => void }) {
  return (
    <Pressable accessibilityRole="switch" accessibilityState={{ checked: value }} onPress={() => onValueChange(!value)} style={styles.row}>
      <View style={styles.copy}>
        <Text style={styles.label}>{label}</Text>
        <Text style={styles.hint}>{hint}</Text>
      </View>
      <Switch value={value} onValueChange={onValueChange} />
    </Pressable>
  );
}

export default function DataUsage() {
  const { t } = useTranslation();
  const settings = useResilience((s) => s.settings);
  const hydrate = useResilience((s) => s.hydrate);
  const updateSettings = useResilience((s) => s.updateSettings);
  const [value, setValue] = useState<SyncSettings>(settings);
  useEffect(() => void hydrate(), [hydrate]);
  useEffect(() => setValue(settings), [settings]);
  const set = (key: keyof SyncSettings, next: boolean) => setValue((current) => ({ ...current, [key]: next }));
  return (
    <Screen>
      <AppHeader title={t("dataUsage")} back />
      <Card>
        <Setting label={t("lowData")} hint={t("lowDataHint")} value={value.low_data_mode} onValueChange={(next) => set("low_data_mode", next)} />
        <Setting label={t("wifiOnly")} hint={t("wifiOnlyHint")} value={value.wifi_only_uploads} onValueChange={(next) => set("wifi_only_uploads", next)} />
        <Setting label={t("compressImages")} hint={t("compressImagesHint")} value={value.compress_images} onValueChange={(next) => set("compress_images", next)} />
      </Card>
      <Button label={t("continue")} onPress={() => void updateSettings(value)} />
      <Card>
        <Text style={styles.label}>{t("accessibility")}</Text>
        <Text style={styles.hint}>{t("accessibilityBody")}</Text>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { minHeight: 64, flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x3 },
  copy: { flex: 1, gap: space.x1 },
  label: { ...type.label, color: colors.navy950 },
  hint: { ...type.meta, color: colors.neutral600 },
});
