import { NativeModules, Platform, TurboModuleRegistry } from "react-native";
import * as Updates from "expo-updates";
import { environmentConfig } from "@/config/environment";
import { scrubBreadcrumb, scrubEvent } from "@/security/crashScrubber";

/**
 * Crash reporting (OPS-05): Sentry, native + JS, source-mapped.
 *
 * Fully disabled unless EXPO_PUBLIC_SENTRY_DSN is set at build/update time
 * (no DSN ships in the repo). The SDK is required lazily and guarded, so an
 * OTA update landing on a binary without the RNSentry native module (e.g.
 * APK 1.5.0) runs JS-only reporting instead of crashing.
 *
 * Privacy: sendDefaultPii off, no screenshots/view hierarchy/replay, every
 * event and breadcrumb passes crashScrubber. Tags carry only app version,
 * runtime version, OTA update id, channel and role — never user ids/names.
 */
type SentryLike = {
  init: (options: Record<string, unknown>) => void;
  setTag: (key: string, value: string) => void;
  captureException: (error: unknown) => void;
  wrap?: <T>(component: T) => T;
};

let sentry: SentryLike | null = null;

export const sentryDsn = (): string => (process.env.EXPO_PUBLIC_SENTRY_DSN ?? "").trim();

function nativeSentryAvailable() {
  try {
    return !!(TurboModuleRegistry.get("RNSentry") ?? (NativeModules as Record<string, unknown>).RNSentry);
  } catch {
    return false;
  }
}

export const CrashReporting = {
  init() {
    const dsn = sentryDsn();
    if (!dsn || sentry || Platform.OS === "web") return;
    try {
      // eslint-disable-next-line @typescript-eslint/no-require-imports
      const sdk = require("@sentry/react-native") as SentryLike;
      const native = nativeSentryAvailable();
      sdk.init({
        dsn,
        environment: environmentConfig.environment,
        // release/dist left to the SDK defaults so they match the source maps
        // uploaded by the Expo plugin (builds) and sentry-expo-upload-sourcemaps (updates).
        enableNative: native,
        enableNativeCrashHandling: native,
        sendDefaultPii: false,
        attachScreenshot: false,
        attachViewHierarchy: false,
        enableCaptureFailedRequests: false,
        maxBreadcrumbs: 40,
        tracesSampleRate: 0,
        beforeSend: (event: Record<string, unknown>) => scrubEvent(event),
        beforeBreadcrumb: (crumb: Record<string, unknown> | null) => scrubBreadcrumb(crumb),
      });
      sentry = sdk;
      sdk.setTag("app_version", environmentConfig.appVersion);
      sdk.setTag("runtime_version", Updates.runtimeVersion ?? "unknown");
      sdk.setTag("update_id", Updates.updateId ?? "embedded");
      sdk.setTag("channel", Updates.channel ?? environmentConfig.releaseChannel);
      sdk.setTag("native_sdk", native ? "yes" : "no");
    } catch {
      sentry = null; // Crash reporting must never break startup.
    }
  },
  /** Role code only (e.g. AGENT) — never a user id or name. */
  setRole(role: string | null | undefined) {
    try {
      sentry?.setTag("role", role ? String(role).slice(0, 40) : "anonymous");
    } catch {
      // ignore
    }
  },
  captureException(error: unknown) {
    try {
      sentry?.captureException(error);
    } catch {
      // ignore
    }
  },
};
