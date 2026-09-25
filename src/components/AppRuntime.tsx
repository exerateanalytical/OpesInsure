import React, { ReactNode, useCallback, useEffect, useRef, useState } from "react";
import { AppState, AppStateStatus, Platform, Pressable, StyleSheet, Text, View } from "react-native";
import * as LocalAuthentication from "expo-local-authentication";
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
import { BiometricLock } from "@/security/biometric";
import { environmentConfig } from "@/config/environment";
import { environmentBanner } from "@/lib/environmentBanner";
import { useTimezone } from "@/store/timezone";
import {
  backoffDelay,
  isIdleExpired,
  lockSuspended,
  resolveLockPolicy,
  shouldRelock,
} from "@/lib/appLock";

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

/**
 * App-wide runtime: privacy cover, biometric lock, idle timeout, runtime
 * gates, offline banner and notification routing.
 *
 * The navigation stack (`children`) is ALWAYS mounted. The privacy cover and
 * the lock screen are absolute-fill overlays on top of it, so a camera,
 * document picker, KYC capture or mobile-money hand-off returns the user to
 * the exact screen and form state they left. Biometrics are asked again
 * only after the grace period in the background (default 60 s).
 */
export function AppRuntime({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const invalidate = useSession((s) => s.invalidate);
  const { t } = useTranslation();
  const online = useResilience((s) => s.online);
  const hydrateResilience = useResilience((s) => s.hydrate);
  const refreshNetwork = useResilience((s) => s.refreshNetwork);
  const syncNow = useResilience((s) => s.syncNow);
  const pendingOps = useResilience((s) => s.summary.pending + s.summary.failed);
  const [locked, setLocked] = useState(false);
  const [privacyCovered, setPrivacyCovered] = useState(false);
  const coldStartChecked = useRef(false);
  const previousStatus = useRef(status);
  const pendingTap = useRef<unknown>(null);
  const backgroundedAt = useRef<number | null>(null);
  const lastActiveAt = useRef<number>(Date.now());
  const authenticating = useRef(false);
  const navigationMounted = useRef(false);
  const gate = useRuntime((s) => s.gate);
  const runtime = useRuntime((s) => s.bootstrap);
  const runtimeIssues = useRuntime((s) => s.issues);
  const checkRuntime = useRuntime((s) => s.check);
  const checkRuntimeIfDue = useRuntime((s) => s.checkIfDue);
  const policyRef = useRef(resolveLockPolicy(runtime?.security, environmentConfig));
  policyRef.current = resolveLockPolicy(runtime?.security, environmentConfig);
  const statusRef = useRef(status);
  statusRef.current = status;
  // Display time zone (GET /me/settings) for every formatted date; reset on sign-out.
  useEffect(() => {
    if (status === "authenticated") void useTimezone.getState().load();
    else if (status === "anonymous") useTimezone.getState().reset();
  }, [status]);
  const envBanner = environmentBanner(runtime?.environment);

  const unlock = useCallback(async () => {
    if (!(await BiometricLock.enabled())) {
      setLocked(false);
      return;
    }
    if (authenticating.current) return;
    authenticating.current = true;
    setLocked(true);
    try {
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: t("unlockPrompt"),
        fallbackLabel: t("unlockFallback"),
        disableDeviceFallback: false,
      });
      setLocked(!result.success);
      if (result.success) lastActiveAt.current = Date.now();
    } catch {
      setLocked(true);
    } finally {
      authenticating.current = false;
    }
  }, [t]);
  const unlockRef = useRef(unlock);
  unlockRef.current = unlock;

  /** Ends the session after the idle timeout (every role). */
  const expireIfIdle = useCallback(() => {
    if (statusRef.current !== "authenticated") return false;
    if (!isIdleExpired(lastActiveAt.current, Date.now(), policyRef.current.idleTimeoutMs)) return false;
    setLocked(false);
    void invalidate().then(() => router.replace("/session-expired" as never));
    return true;
  }, [invalidate]);

  // Cold start: a restored session is locked behind biometrics before any
  // insurance data renders (resume is handled by the AppState listener).
  // Push registration and a notification that launched the app run after.
  useEffect(() => {
    const previous = previousStatus.current;
    previousStatus.current = status;
    if (status !== "authenticated") {
      if (status === "anonymous") {
        coldStartChecked.current = false;
        setLocked(false);
        resetPushRegistration();
      }
      return;
    }
    lastActiveAt.current = Date.now();
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

  // Runtime gate + network: on launch and on foreground only. No interval
  // polling while online (metered data); while offline the network is
  // re-checked with exponential backoff until it comes back (below).
  useEffect(() => {
    void checkRuntime();
    void hydrateResilience();
    const removeSessionListener = onSessionExpired(() => {
      void invalidate().then(() => router.replace("/session-expired" as never));
    });
    const onChange = (next: AppStateStatus) => {
      if (next === "background") {
        backgroundedAt.current ??= Date.now();
        setPrivacyCovered(true);
        return;
      }
      if (next === "inactive") {
        // iOS app-switcher snapshot only. The Face ID prompt also makes the
        // app inactive, so "inactive" never starts the re-lock clock.
        if (Platform.OS === "ios" && !authenticating.current) setPrivacyCovered(true);
        return;
      }
      // active
      const since = backgroundedAt.current;
      backgroundedAt.current = null;
      setPrivacyCovered(false);
      void checkRuntimeIfDue();
      void refreshNetwork().then((up) => (up ? syncNow() : undefined));
      if (statusRef.current !== "authenticated") return;
      if (since !== null && isIdleExpired(since, Date.now(), policyRef.current.idleTimeoutMs)) {
        lastActiveAt.current = since;
        expireIfIdle();
        return;
      }
      if (shouldRelock(since, Date.now(), policyRef.current.relockGraceMs, lockSuspended()))
        void unlockRef.current();
      else lastActiveAt.current = Date.now();
    };
    const app = AppState.addEventListener("change", onChange);
    const notification = Notifications.addNotificationResponseReceivedListener(
      (response) => {
        const data = response.notification.request.content.data;
        // A tap before sign-in completes is replayed once the session is up.
        if (useSession.getState().status !== "authenticated") pendingTap.current = data;
        else openFromNotification(data);
      },
    );
    return () => {
      app.remove();
      notification.remove();
      removeSessionListener();
    };
  }, [hydrateResilience, refreshNetwork, syncNow, checkRuntime, checkRuntimeIfDue, invalidate, expireIfIdle]);

  // Offline: re-check reachability with backoff (5 s → 5 min), then sync.
  useEffect(() => {
    if (online) return;
    let failures = 0;
    let timer: ReturnType<typeof setTimeout>;
    const tick = async () => {
      if (AppState.currentState === "active" && (await refreshNetwork())) {
        void syncNow();
        return;
      }
      failures += 1;
      timer = setTimeout(() => void tick(), backoffDelay(failures));
    };
    timer = setTimeout(() => void tick(), backoffDelay(0));
    return () => clearTimeout(timer);
  }, [online, refreshNetwork, syncNow]);

  // Foreground idle timeout: no touch for idleTimeout ends the session.
  useEffect(() => {
    if (status !== "authenticated") return;
    const timer = setInterval(() => {
      if (AppState.currentState === "active") expireIfIdle();
    }, 30_000);
    return () => clearInterval(timer);
  }, [status, expireIfIdle]);

  const blocked = gate !== "ready" && gate !== "checking";
  // Before the stack has ever mounted (cold start with a bad configuration,
  // maintenance or forced update) the gate replaces it. Once mounted, a gate
  // raised on resume is an overlay: unmounting would reset navigation and
  // drop the user on the first allowed route.
  if (blocked && !navigationMounted.current)
    return (
      <RuntimeGateView
        gate={gate}
        bootstrap={runtime}
        issues={runtimeIssues}
        retry={() => void checkRuntime()}
      />
    );
  navigationMounted.current = true;
  const overlay = blocked ? "gate" : privacyCovered ? "privacy" : locked ? "lock" : null;
  return (
    <View
      style={styles.flex}
      // Any touch counts as activity for the idle timeout. Capture phase,
      // returns false: it never steals the responder.
      onStartShouldSetResponderCapture={() => {
        lastActiveAt.current = Date.now();
        return false;
      }}
    >
      <View
        style={styles.flex}
        importantForAccessibility={overlay ? "no-hide-descendants" : "auto"}
        accessibilityElementsHidden={!!overlay}
      >
        <UpdateNotice />
        {envBanner ? (
          <View accessibilityRole="text" style={styles.env}>
            <Text style={styles.envText}>{envBanner.kind === "demo" ? t("envBannerDemo") : t("envBannerGeneric", { banner: envBanner.banner })}</Text>
          </View>
        ) : null}
        {!online ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${t("offlineBanner")} ${t("viewSync")}`}
            onPress={() => router.push("/sync" as never)}
            style={styles.offline}
          >
            <Text style={styles.offlineText}>
              {t("offlineBanner")} · {t("viewSync")}
              {pendingOps > 0 ? ` · ${t("offlinePendingCount", { count: pendingOps })}` : ""}
            </Text>
          </Pressable>
        ) : null}
        <View style={styles.flex}>{children}</View>
        <IssueReportButton />
      </View>
      {/* Overlays: the stack underneath stays mounted (never unmount it). */}
      {overlay === "gate" ? (
        <View style={[StyleSheet.absoluteFill, styles.gate]} accessibilityViewIsModal>
          <RuntimeGateView
            gate={gate}
            bootstrap={runtime}
            issues={runtimeIssues}
            retry={() => void checkRuntime()}
          />
        </View>
      ) : overlay === "privacy" ? (
        <View style={[StyleSheet.absoluteFill, styles.privacy]} accessibilityViewIsModal>
          <Text style={styles.privacyTitle}>OpesInsure</Text>
          <Text style={styles.privacyBody}>{t("privacyCover")}</Text>
        </View>
      ) : overlay === "lock" ? (
        <View style={[StyleSheet.absoluteFill, styles.lock]} accessibilityViewIsModal>
          <Card feature>
            <Text accessibilityRole="header" style={styles.title}>{t("lockedAppTitle")}</Text>
            <Text style={styles.body}>{t("lockedAppBody")}</Text>
            <Button label={t("unlockSecurely")} onPress={() => void unlock()} />
          </Card>
        </View>
      ) : null}
    </View>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  offline: {
    backgroundColor: colors.warningSoft,
    paddingHorizontal: space.x4,
    paddingVertical: space.x2,
    minHeight: 44,
    justifyContent: "center",
  },
  offlineText: { ...type.meta, color: colors.warningText, textAlign: "center" },
  env: { backgroundColor: colors.navy950, paddingHorizontal: space.x4, paddingVertical: space.x1 },
  envText: { ...type.caption, color: colors.white, textAlign: "center" },
  lock: {
    zIndex: 1000,
    elevation: 1000,
    justifyContent: "center",
    padding: space.x5,
    backgroundColor: colors.neutral50,
  },
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  gate: { zIndex: 1002, elevation: 1002, backgroundColor: colors.neutral50 },
  privacy: {
    zIndex: 1001,
    elevation: 1001,
    alignItems: "center",
    justifyContent: "center",
    padding: space.x5,
    backgroundColor: colors.white,
  },
  privacyTitle: { ...type.pageTitle, color: colors.navy950 },
  privacyBody: { ...type.body, color: colors.neutral600, textAlign: "center" },
});
