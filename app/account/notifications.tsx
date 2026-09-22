import React, { useEffect, useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import * as Notifications from "expo-notifications";
import { AccountApi, NotificationPreferences } from "@/api/client";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { colors, space, type } from "@/theme/tokens";
const defaults: NotificationPreferences = {
  push: false,
  sms: true,
  email: false,
  renewals: true,
  claims: true,
  payments: true,
};
export default function Preferences() {
  const [value, setValue] = useState(defaults);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    AccountApi.notificationPreferences()
      .then(setValue)
      .catch(() => {});
  }, []);
  const toggle = async (key: keyof NotificationPreferences, next: boolean) => {
    if (key === "push" && next) {
      const permission = await Notifications.requestPermissionsAsync();
      if (!permission.granted) {
        setError("Push notification permission was not granted.");
        return;
      }
      const token = await Notifications.getDevicePushTokenAsync();
      await AccountApi.registerPush(String(token.data));
    }
    setValue((v) => ({ ...v, [key]: next }));
  };
  const save = async () => {
    setBusy(true);
    try {
      setValue(await AccountApi.saveNotificationPreferences(value));
      setError(null);
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Preferences could not be saved.",
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title="Notifications" back />
      <Card>
        {(Object.keys(value) as (keyof NotificationPreferences)[]).map(
          (key) => (
            <View key={key} style={styles.row}>
              <Text style={styles.label}>
                {key.charAt(0).toUpperCase() + key.slice(1)}
              </Text>
              <Switch
                value={value[key]}
                onValueChange={(next) => void toggle(key, next)}
                trackColor={{ true: colors.blue600 }}
              />
            </View>
          ),
        )}
      </Card>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button
        label="Save preferences"
        loading={busy}
        onPress={() => void save()}
      />
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
});
