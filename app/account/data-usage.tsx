import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { Accessibility, Gauge, ImageDown, LucideIcon, Wifi } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { useTranslation } from "@/i18n";
import { SyncSettings } from "@/offline/types";
import { useResilience } from "@/store/resilience";
import { colors, radius, space, type } from "@/theme/tokens";

function Setting({ icon, label, hint, value, onValueChange, last }: { icon: LucideIcon; label: string; hint: string; value: boolean; onValueChange: (value: boolean) => void; last?: boolean }) {
  return (
    <Pressable accessibilityRole="switch" accessibilityState={{ checked: value }} onPress={() => onValueChange(!value)} style={[styles.row, last && styles.rowLast]}>
      <TintedIcon icon={icon} tint={value ? "blue" : "neutral"} size={40} />
      <View style={styles.copy}>
        <Text style={styles.label}>{label}</Text>
        <Text style={styles.hint}>{hint}</Text>
      </View>
      <Switch value={value} onValueChange={onValueChange} trackColor={{ true: colors.blue600 }} />
    </Pressable>
  );
}

export default function DataUsage() {
  const { t } = useTranslation();
  const settings = useResilience((s) => s.settings);
  const hydrate = useResilience((s) => s.hydrate);
  const updateSettings = useResilience((s) => s.updateSettings);
  const [value, setValue] = useState<SyncSettings>(settings);
  const [saved, setSaved] = useState(false);
  useEffect(() => void hydrate(), [hydrate]);
  useEffect(() => setValue(settings), [settings]);
  const set = (key: keyof SyncSettings, next: boolean) => {
    setSaved(false);
    setValue((current) => ({ ...current, [key]: next }));
  };
  const dirty = (Object.keys(value) as (keyof SyncSettings)[]).some((k) => value[k] !== settings[k]);
  return (
    <Screen>
      <BrandHeader title={t("dataUsage")} subtitle={t("dataUsageSubtitle")} back right={null} />
      <Card style={styles.card}>
        <Setting icon={Gauge} label={t("lowData")} hint={t("lowDataHint")} value={value.low_data_mode} onValueChange={(next) => set("low_data_mode", next)} />
        <Setting icon={Wifi} label={t("wifiOnly")} hint={t("wifiOnlyHint")} value={value.wifi_only_uploads} onValueChange={(next) => set("wifi_only_uploads", next)} />
        <Setting icon={ImageDown} label={t("compressImages")} hint={t("compressImagesHint")} value={value.compress_images} onValueChange={(next) => set("compress_images", next)} last />
      </Card>
      {saved ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{t("settingsSaved")}</Text> : null}
      <Button
        label={t("saveChanges")}
        disabled={!dirty}
        onPress={() =>
          void updateSettings(value).then(() => setSaved(true))
        }
      />
      <Card style={styles.card}>
        <SectionHeading title={t("accessibility")} icon={Accessibility} />
        <Text style={styles.hint}>{t("accessibilityBody")}</Text>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: 0 },
  row: { minHeight: 64, flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x3, borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  rowLast: { borderBottomWidth: 0 },
  copy: { flex: 1, gap: space.x1 },
  label: { ...type.label, color: colors.navy950 },
  hint: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
});
