import React, { ReactNode, useEffect, useRef, useState } from "react";
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
import { IssueReportButton } from "@/components/IssueReportButton";
import { UpdateNotice } from "@/components/UpdateNotice";
import { resolveNotificationTarget } from "@/lib/customerLogic";
import { registerForPush, resetPushRegistration } from "@/notifications/push";
const biometricKey = "opesinsure.biometric_enabled";

// Foreground notifications still show a banner (the inbox badge updates on
// focus); taps are routed below.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: false,
    shouldSetBadge: true,
  }),
});

/** Opens a notification's target, but only a validated in-app route and
 * only for a signed-in user (the guard would bounce anything else). */
const openFromNotification = (data: unknown) => {
  const target = resolveNotificationTarget(data);
  if (target && useSession.getState().status === "authenticated") router.push(target as never);
};
export function AppRuntime({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const invalidate = useSession((s) => s.invalidate);
  const { t } = useTranslation();
  const online = useResilience((s) => s.online);
  const hydrateResilience = useResilience((s) => s.hydrate);
  const refreshNetwork = useResilience((s) => s.refreshNetwork);
  const syncNow = useResilience((s) => s.syncNow);
  const [locked, setLocked] = useState(false);
  const coldStartChecked = useRef(false);
  const previousStatus = useRef(status);
  const pendingTap = useRef<unknown>(null);
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
    setLocked(true);
    try {
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: t("unlockPrompt"),
        fallbackLabel: t("unlockFallback"),
        disableDeviceFallback: false,
      });
      setLocked(!result.success);
    } catch {
      setLocked(true);
    }
  };

  // Cold start: a restored session is locked behind biometrics before any
  // insurance data renders (resume is handled by the AppState listener).
  // Push registration and a notification that launched the app run after.
  useEffect(() => {
    const previous = previousStatus.current;
    previousStatus.current = status;
    if (status !== "authenticated") {
      if (status === "anonymous") {
        coldStartChecked.current = false;
        resetPushRegistration();
      }
      return;
    }
    void registerForPush();
    if (coldStartChecked.current) return;
    coldStartChecked.current = true;
    // Restored from storage (booting → authenticated) = cold start: lock.
    // A fresh sign-in has just proven the user, so no second prompt.
    const restored = previous === "booting" || previous === "error";
    void (async () => {
      if (restored) await unlock();
      const launch = await Notifications.getLastNotificationResponseAsync().catch(() => null);
      const data = launch?.notification.request.content.data ?? pendingTap.current;
      pendingTap.current = null;
      if (data) openFromNotification(data);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);
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
        const data = response.notification.request.content.data;
        // A tap before sign-in completes is replayed once the session is up.
        if (useSession.getState().status !== "authenticated") pendingTap.current = data;
        else openFromNotification(data);
      },
    );
    return () => {
      clearInterval(timer);
      app.remove();
      notification.remove();
      removeSessionListener();
    };
    // `unlock` is recreated each render; the listener only needs the latest
    // status, which is already a dependency.
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
        <Text style={styles.privacyBody}>{t("privacyCover")}</Text>
      </View>
    );
  if (locked)
    return (
      <View style={styles.lock}>
        <Card feature>
          <Text accessibilityRole="header" style={styles.title}>{t("lockedAppTitle")}</Text>
          <Text style={styles.body}>{t("lockedAppBody")}</Text>
          <Button label={t("unlockSecurely")} onPress={() => void unlock()} />
        </Card>
      </View>
    );
  return (
    <View style={styles.flex}>
      <UpdateNotice />
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
      <IssueReportButton />
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
    backgroundColor: colors.white,
  },
  privacyTitle: { ...type.pageTitle, color: colors.navy950 },
  privacyBody: { ...type.body, color: colors.neutral600, textAlign: "center" },
});
