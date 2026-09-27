/**
 * Device attestation (SEC-007 / SEC-008 / FRAUD-001).
 *
 * Google Play Integrity (Android) and App Attest (iOS) need a native module
 * that is not in the current APK. The module is looked up with
 * requireOptionalNativeModule so an OTA update running on an older APK never
 * crashes: it simply reports "UNAVAILABLE_MANAGED_RUNTIME" and the server's
 * risk policy decides (LIMIT / step-up), never the client.
 *
 * Native side: local Expo module modules/opes-integrity ("OpesIntegrity"; needs
 * PLAY_INTEGRITY_CLOUD_PROJECT_NUMBER at build time, else rejects NOT_CONFIGURED) exposing
 *   requestToken(nonce: string): Promise<string>   // Play Integrity / App Attest token
 *   localSignals(): Promise<{ debuggable?: boolean; emulator?: boolean; hooked?: boolean; rooted?: boolean }>
 * Those local signals are hints only; the verdict is the server's.
 */
import { Platform } from "react-native";
import { requireOptionalNativeModule } from "expo";
import { DeviceSecurityApi } from "@/api/client";
import { environmentConfig } from "@/config/environment";

type IntegrityModule = {
  requestToken?: (nonce: string) => Promise<string>;
  localSignals?: () => Promise<Record<string, boolean | undefined>>;
};

function nativeIntegrity(): IntegrityModule | null {
  if (Platform.OS === "web") return null;
  try {
    return requireOptionalNativeModule<IntegrityModule>("OpesIntegrity");
  } catch {
    return null;
  }
}

export const DeviceAttestation = {
  /** True when this binary carries the native integrity module. */
  available: () => Boolean(nativeIntegrity()?.requestToken),
  /** Runs a server-verified assessment. The server alone decides ALLOW/LIMIT/BLOCK. */
  async assess() {
    const challenge = await DeviceSecurityApi.nonce();
    const native = nativeIntegrity();
    let token: string | null = null;
    if (native?.requestToken) {
      try {
        token = await native.requestToken(challenge.nonce);
      } catch {
        token = null; // Provider failure is reported as "unavailable"; the server applies its policy.
      }
    }
    const platform = Platform.OS === "ios" ? "IOS" : "ANDROID";
    return DeviceSecurityApi.assess({
      nonce: challenge.nonce,
      platform,
      provider: token ? (platform === "IOS" ? "APP_ATTEST" : "PLAY_INTEGRITY") : "UNAVAILABLE_MANAGED_RUNTIME",
      attestation_token: token,
      app_version: environmentConfig.appVersion,
    });
  },
};
