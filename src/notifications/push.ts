import { Linking, Platform } from "react-native";
import Constants from "expo-constants";
import * as Notifications from "expo-notifications";
import { CustomerApi } from "@/api/customer";
import { Telemetry } from "@/security/telemetry";

let registeredToken: string | null = null;
let tokenListener: { remove(): void } | null = null;

/** Android channels created before any permission prompt / token request
 * (Android 8+ drops notifications posted to an unknown channel; Android 13+
 * only shows the POST_NOTIFICATIONS prompt once a channel exists). */
export const ANDROID_CHANNELS = [
  { id: "default", name: "OpesInsure", importance: "DEFAULT" },
  { id: "claims", name: "Claims", importance: "HIGH" },
  { id: "payments", name: "Payments", importance: "HIGH" },
  { id: "policies", name: "Policies & renewals", importance: "DEFAULT" },
] as const;

export type PushResult =
  | { status: "registered"; token: string }
  | { status: "denied"; canAskAgain: boolean }
  | { status: "unavailable"; reason: string };

const report = (reason: string) =>
  // Backend allow-list has no push-specific code; API_ERROR + error_code is
  // the accepted shape (config/mobile_runtime.php telemetry).
  void Telemetry.capture("API_ERROR", { error_code: "PUSH_REGISTRATION_FAILED", reason: reason.slice(0, 80) });

async function ensureChannels() {
  if (Platform.OS !== "android") return;
  for (const channel of ANDROID_CHANNELS) {
    await Notifications.setNotificationChannelAsync(channel.id, {
      name: channel.name,
      importance: Notifications.AndroidImportance[channel.importance],
    });
  }
}

const projectIdOf = () =>
  (Constants.expoConfig?.extra?.eas?.projectId as string | undefined) ??
  (Constants.easConfig?.projectId as string | undefined);

async function send(token: string) {
  if (token === registeredToken) return;
  await CustomerApi.registerPushToken(token);
  registeredToken = token;
}

/**
 * Registers this device's Expo push token with the backend
 * (POST /mobile/account/push-tokens {token, provider: "expo", platform}).
 * Creates Android channels first, then asks for permission (POST_NOTIFICATIONS
 * on Android 13+). `prompt: false` only registers when already granted.
 * Re-registers when the native token rotates. Never throws.
 */
export async function registerForPush(options: { prompt?: boolean } = {}): Promise<PushResult> {
  const prompt = options.prompt ?? true;
  try {
    await ensureChannels();
    let permission = await Notifications.getPermissionsAsync();
    if (!permission.granted && prompt && permission.canAskAgain) {
      permission = await Notifications.requestPermissionsAsync();
    }
    if (!permission.granted) return { status: "denied", canAskAgain: permission.canAskAgain };
    const projectId = projectIdOf();
    if (!projectId) {
      report("missing_project_id");
      return { status: "unavailable", reason: "missing_project_id" };
    }
    const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });
    await send(token);
    if (!tokenListener) {
      // The native (FCM/APNs) token rotated: fetch the new Expo token and re-register.
      tokenListener = Notifications.addPushTokenListener(() => {
        void Notifications.getExpoPushTokenAsync({ projectId })
          .then(({ data }) => send(data))
          .catch((e: unknown) => report(e instanceof Error ? e.message : "token_refresh_failed"));
      });
    }
    return { status: "registered", token };
  } catch (e) {
    // Typical cause without google-services.json: "Default FirebaseApp is not initialized".
    const reason = e instanceof Error ? e.message : "unknown";
    report(reason);
    return { status: "unavailable", reason };
  }
}

/** Opens the OS settings page for this app so a denied user can enable notifications. */
export const openNotificationSettings = () => {
  void Linking.openSettings().catch(() => undefined);
};

/** Forget the cached token so the next sign-in registers it again. */
export const resetPushRegistration = () => {
  registeredToken = null;
  tokenListener?.remove();
  tokenListener = null;
};
