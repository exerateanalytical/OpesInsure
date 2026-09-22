import React, { ReactNode, useEffect, useState } from "react";
import { AppState, Pressable, StyleSheet, Text, View } from "react-native";
import * as LocalAuthentication from "expo-local-authentication";
import * as SecureStore from "expo-secure-store";
import * as Notifications from "expo-notifications";
import { router } from "expo-router";
import { Button, Card } from "@/components/ui";
import { useSession } from "@/store/session";
import { colors, space, type } from "@/theme/tokens";
import { useResilience } from "@/store/resilience";
import { useTranslation } from "@/i18n";
const biometricKey = "opesinsure.biometric_enabled";
const safePaths = [
  "/(customer)/(tabs)/policies",
  "/(customer)/(tabs)/claims",
  "/payment",
  "/confirmation",
];
export function AppRuntime({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const { t } = useTranslation();
  const online = useResilience((s) => s.online);
  const hydrateResilience = useResilience((s) => s.hydrate);
  const refreshNetwork = useResilience((s) => s.refreshNetwork);
  const syncNow = useResilience((s) => s.syncNow);
  const [locked, setLocked] = useState(false);
  const unlock = async () => {
    const enabled = await SecureStore.getItemAsync(biometricKey);
    if (enabled !== "true") {
      setLocked(false);
      return;
    }
    const result = await LocalAuthentication.authenticateAsync({
      promptMessage: "Unlock OpesInsure",
      fallbackLabel: "Use device credential",
      disableDeviceFallback: false,
    });
    setLocked(!result.success);
  };
  useEffect(() => {
    const check = async () => {
      if (await refreshNetwork()) void syncNow();
    };
    void hydrateResilience();
    const timer = setInterval(() => void check(), 10000);
    const app = AppState.addEventListener("change", (next) => {
      if (next === "active" && status === "authenticated") void unlock();
    });
    const notification = Notifications.addNotificationResponseReceivedListener(
      (response) => {
        const path = response.notification.request.content.data?.path;
        if (typeof path === "string" && safePaths.includes(path))
          router.push(path as never);
      },
    );
    return () => {
      clearInterval(timer);
      app.remove();
      notification.remove();
    };
  }, [status, hydrateResilience, refreshNetwork, syncNow]);
  if (locked)
    return (
      <View style={styles.lock}>
        <Card feature>
          <Text style={styles.title}>OpesInsure is locked</Text>
          <Text style={styles.body}>
            Authenticate with this device before accessing insurance and
            financial information.
          </Text>
          <Button label="Unlock securely" onPress={() => void unlock()} />
        </Card>
      </View>
    );
  return (
    <View style={styles.flex}>
      {!online ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${t("offlineBanner")} ${t("viewSync")}`}
          onPress={() => router.push("/sync" as never)}
          style={styles.offline}
        >
          <Text style={styles.offlineText}>
            {t("offlineBanner")} · {t("viewSync")}
          </Text>
        </Pressable>
      ) : null}
      <View style={styles.flex}>{children}</View>
    </View>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  offline: {
    backgroundColor: colors.warningSoft,
    paddingHorizontal: space.x4,
    paddingVertical: space.x2,
  },
  offlineText: { ...type.meta, color: colors.warningText, textAlign: "center" },
  lock: {
    flex: 1,
    justifyContent: "center",
    padding: space.x5,
    backgroundColor: colors.neutral50,
  },
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
