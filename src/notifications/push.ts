import { Platform } from "react-native";
import Constants from "expo-constants";
import * as Notifications from "expo-notifications";
import { CustomerApi } from "@/api/customer";

let registeredToken: string | null = null;

/**
 * Registers this device's Expo push token with the backend
 * (POST /mobile/account/push-tokens {token, provider: "expo", platform}).
 * Asks for permission once; silently does nothing when it is refused, on
 * simulators, or when the project id is missing. Never throws.
 */
export async function registerForPush(): Promise<string | null> {
  try {
    if (Platform.OS === "android") {
      await Notifications.setNotificationChannelAsync("default", {
        name: "OpesInsure",
        importance: Notifications.AndroidImportance.DEFAULT,
      });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== "granted") ({ status } = await Notifications.requestPermissionsAsync());
    if (status !== "granted") return null;
    const projectId =
      (Constants.expoConfig?.extra?.eas?.projectId as string | undefined) ??
      (Constants.easConfig?.projectId as string | undefined);
    if (!projectId) return null;
    const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });
    if (token && token !== registeredToken) {
      await CustomerApi.registerPushToken(token);
      registeredToken = token;
    }
    return token;
  } catch {
    return null;
  }
}

/** Forget the cached token so the next sign-in registers it again. */
export const resetPushRegistration = () => {
  registeredToken = null;
};
