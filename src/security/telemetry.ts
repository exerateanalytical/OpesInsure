import { Platform } from "react-native";
import * as Crypto from "expo-crypto";
import { RuntimeApi } from "@/api/client";
import { environmentConfig } from "@/config/environment";

/**
 * Event names the backend accepts (config/mobile_runtime.php
 * telemetry.events). Anything else is rejected with 422, so crash reports
 * must use these exact codes.
 */
export type TelemetryEvent =
  | "APP_LAUNCHED" | "APP_CRASHED" | "APP_FOREGROUNDED" | "APP_BACKGROUNDED"
  | "SCREEN_VIEWED" | "API_ERROR" | "NETWORK_TIMEOUT" | "JS_EXCEPTION"
  | "PAYMENT_FAILED" | "OFFLINE_SYNC_FAILED" | "STEP_UP_CHALLENGE_FAILED"
  | "FORCE_UPDATE_SHOWN" | "MAINTENANCE_SHOWN" | "DEVICE_RISK_LIMITED";

const forbidden = /name|email|phone|token|address|document|payload|body|pin|otp|password/i;
const clean = (value: Record<string, unknown>) =>
  Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !forbidden.test(key))
      .filter(([, item]) => item === null || ["string", "number", "boolean"].includes(typeof item))
      .slice(0, 20)
      .map(([key, item]) => [key, typeof item === "string" ? item.slice(0, 120) : item]),
  );

// Current route (path only, ids masked) so crashes can be grouped by screen.
let currentScreen = "unknown";
const maskPath = (path: string) =>
  (path.split("?")[0] ?? "").replace(/\/[0-9a-f-]{6,}|\/\d+/gi, "/:id").slice(0, 120);

let handlersInstalled = false;
const launchedAt = Date.now();

export const Telemetry = {
  setScreen(path: string) {
    currentScreen = maskPath(path || "/");
  },
  get screen() {
    return currentScreen;
  },
  async capture(event: TelemetryEvent, attributes: Record<string, unknown> = {}) {
    if (environmentConfig.demoMode) return;
    try {
      await RuntimeApi.telemetry({
        event,
        correlation_id: Crypto.randomUUID(),
        app_version: environmentConfig.appVersion,
        release_channel: environmentConfig.releaseChannel,
        attributes: clean({ screen: currentScreen, platform: Platform.OS, ...attributes }),
      });
    } catch {
      // Telemetry must never block an insurance operation or expose its payload.
    }
  },
  /** Startup time from JS module evaluation to first usable render. */
  launched() {
    void Telemetry.capture("APP_LAUNCHED", { duration_ms: Date.now() - launchedAt });
  },
  /**
   * Reports uncaught JS errors (fatal → APP_CRASHED, otherwise JS_EXCEPTION)
   * and then defers to React Native's default handler. Only the error class
   * is sent — never the message, which may contain personal data.
   */
  installGlobalHandlers() {
    if (handlersInstalled) return;
    handlersInstalled = true;
    const utils = (globalThis as { ErrorUtils?: {
      getGlobalHandler: () => (error: Error, isFatal?: boolean) => void;
      setGlobalHandler: (handler: (error: Error, isFatal?: boolean) => void) => void;
    } }).ErrorUtils;
    if (!utils) return;
    const previous = utils.getGlobalHandler();
    utils.setGlobalHandler((error, isFatal) => {
      void Telemetry.capture(isFatal ? "APP_CRASHED" : "JS_EXCEPTION", {
        reason: `${isFatal ? "fatal" : "nonfatal"}:${error?.name ?? "Error"}`,
      });
      previous(error, isFatal);
    });
  },
};
