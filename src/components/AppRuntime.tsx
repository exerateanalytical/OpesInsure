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
import { onSessionExpired } from "@/api/client";
import { useRuntime } from "@/store/runtime";
import { RuntimeGateView } from "@/components/RuntimeGate";
const biometricKey = "opesinsure.biometric_enabled";
const safePaths = [
  "/(customer)/(tabs)/policies",
  "/(customer)/(tabs)/claims",
  "/payment",
  "/confirmation",
];
export function AppRuntime({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const invalidate = useSession((s) => s.invalidate);
  const { t } = useTranslation();
  const online = useResilience((s) => s.online);
  const hydrateResilience = useResilience((s) => s.hydrate);
  const refreshNetwork = useResilience((s) => s.refreshNetwork);
  const syncNow = useResilience((s) => s.syncNow);
  const [locked, setLocked] = useState(false);
  const [privacyCovered, setPrivacyCovered] = useState(false);
  const gate = useRuntime((s) => s.gate);
  const runtime = useRuntime((s) => s.bootstrap);
  const runtimeIssues = useRuntime((s) => s.issues);
  const checkRuntime = useRuntime((s) => s.check);
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
    void checkRuntime();
    const removeSessionListener = onSessionExpired(() => {
      void invalidate().then(() => router.replace("/session-expired" as never));
    });
    const check = async () => {
      if (await refreshNetwork()) void syncNow();
      void checkRuntime();
    };
    void hydrateResilience();
    const timer = setInterval(() => void check(), 10000);
    const app = AppState.addEventListener("change", (next) => {
      setPrivacyCovered(next !== "active");
      if (next === "active" && status === "authenticated")
        void unlock().finally(() => setPrivacyCovered(false));
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
      removeSessionListener();
    };
  }, [status, hydrateResilience, refreshNetwork, syncNow, checkRuntime, invalidate]);
  if (gate !== "ready" && gate !== "checking")
    return (
      <RuntimeGateView
        gate={gate}
        bootstrap={runtime}
        issues={runtimeIssues}
        retry={() => void checkRuntime()}
      />
    );
  if (privacyCovered)
    return (
      <View style={styles.privacy}>
        <Text style={styles.privacyTitle}>OpesInsure</Text>
        <Text style={styles.privacyBody}>Sensitive information is hidden while the app is inactive.</Text>
      </View>
    );
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
  privacy: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    padding: space.x5,
    backgroundColor: colors.navy950,
  },
  privacyTitle: { ...type.pageTitle, color: colors.white },
  privacyBody: { ...type.body, color: colors.neutral200, textAlign: "center" },
});
