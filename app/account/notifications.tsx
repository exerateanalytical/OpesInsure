import React, { useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import * as Notifications from "expo-notifications";
import { AccountApi, NotificationPreferences } from "@/api/client";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { errorMessage } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

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
        const permission = await Notifications.requestPermissionsAsync();
        if (!permission.granted) {
          setError(t("notifPushDenied"));
          return;
        }
        const token = await Notifications.getDevicePushTokenAsync();
        await AccountApi.registerPush(String(token.data));
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
      <AppHeader title={t("notifPrefsTitle")} back />
      <StatePanel loading={loading} error={loadError} data={value} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("notifPrefsLoading")}>
        {(current) => (
          <>
            <Card>
              {(Object.keys(current) as (keyof NotificationPreferences)[]).map((key) => (
                <View key={key} style={styles.row}>
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
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: space.x3,
  },
  label: { ...type.body, color: colors.navy950 },
  error: { ...type.meta, color: colors.dangerText },
  ok: { ...type.meta, color: colors.successText },
});
