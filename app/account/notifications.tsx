import React, { useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import { Bell, BellRing, Mail, MessageSquare, type LucideIcon } from "lucide-react-native";
import { AccountApi, NotificationPreferences } from "@/api/client";
import { Button, Card, Screen } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { errorMessage } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { openNotificationSettings, registerForPush } from "@/notifications/push";
import { colors, radius, space, type } from "@/theme/tokens";

const channelIcon = (key: string): LucideIcon => (key === "push" ? BellRing : key === "email" ? Mail : key === "sms" ? MessageSquare : Bell);

export default function Preferences() {
  const { t, td } = useTranslation();
  const { data: value, setData: setValue, loading, error: loadError, reload } = useLoad(() => AccountApi.notificationPreferences(), []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const toggle = async (current: NotificationPreferences, key: keyof NotificationPreferences, next: boolean) => {
    setSaved(false);
    setError(null);
    try {
      if (key === "push" && next) {
        const result = await registerForPush();
        if (result.status === "denied") {
          setError(t("notifPushDenied"));
          if (!result.canAskAgain) openNotificationSettings();
          return;
        }
        if (result.status !== "registered") {
          setError(t("errGeneric"));
          return;
        }
      }
      setValue({ ...current, [key]: next });
    } catch (e) {
      setError(errorMessage(e, t("errGeneric")));
    }
  };
  const save = async (current: NotificationPreferences) => {
    setBusy(true);
    try {
      setValue(await AccountApi.saveNotificationPreferences(current));
      setError(null);
      setSaved(true);
    } catch (e) {
      setError(errorMessage(e, t("errGeneric")));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <BrandHeader title={t("notifPrefsTitle")} back right={null} />
      <StatePanel loading={loading} error={loadError} data={value} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("notifPrefsLoading")}>
        {(current) => {
          const keys = Object.keys(current) as (keyof NotificationPreferences)[];
          return (
            <>
              <Card style={styles.card}>
                {keys.map((key, i) => (
                  <View key={key} style={[styles.row, i === keys.length - 1 && styles.rowLast]}>
                    <TintedIcon icon={channelIcon(key)} tint={current[key] ? "blue" : "neutral"} size={40} />
                    <Text style={styles.label}>{td(`notifPref_${key}`, key)}</Text>
                    <Switch
                      accessibilityLabel={td(`notifPref_${key}`, key)}
                      value={current[key]}
                      onValueChange={(next) => void toggle(current, key, next)}
                      trackColor={{ true: colors.blue600 }}
                    />
                  </View>
                ))}
              </Card>
              {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
              {saved ? <Text accessibilityLiveRegion="polite" style={styles.ok}>{t("notifPrefsSaved")}</Text> : null}
              <Button label={t("notifPrefsSave")} loading={busy} onPress={() => void save(current)} />
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: 0 },
  row: {
    minHeight: 60,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingVertical: space.x2,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  rowLast: { borderBottomWidth: 0 },
  label: { ...type.body, color: colors.navy950, flex: 1 },
  error: { ...type.meta, color: colors.dangerText },
  ok: { ...type.meta, color: colors.successText },
});
