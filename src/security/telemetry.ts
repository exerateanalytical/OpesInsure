import * as Crypto from "expo-crypto";
import { RuntimeApi } from "@/api/client";
import { environmentConfig } from "@/config/environment";

const forbidden = /name|email|phone|token|address|document|payload|body|pin|otp|password/i;
const clean = (value: Record<string, unknown>) =>
  Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !forbidden.test(key))
      .slice(0, 20)
      .map(([key, item]) => [key, typeof item === "string" ? item.slice(0, 120) : item]),
  );

export const Telemetry = {
  async capture(event: string, attributes: Record<string, unknown> = {}) {
    if (environmentConfig.demoMode) return;
    try {
      await RuntimeApi.telemetry({
        event: event.replace(/[^A-Z0-9_.-]/gi, "_").slice(0, 64),
        correlation_id: Crypto.randomUUID(),
        app_version: environmentConfig.appVersion,
        release_channel: environmentConfig.releaseChannel,
        attributes: clean(attributes),
      });
    } catch {
      // Telemetry must never block an insurance operation or expose its payload.
    }
  },
};
