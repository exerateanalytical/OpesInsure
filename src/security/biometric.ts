import * as SecureStore from "@/security/secureStore";
import { DEVICE_ONLY } from "@/security/secureJson";

/** One key for the biometric-lock opt-in, shared by every portal. */
export const BIOMETRIC_KEY = "opesinsure.biometric_enabled";

export const BiometricLock = {
  enabled: async () => (await SecureStore.getItemAsync(BIOMETRIC_KEY).catch(() => null)) === "true",
  enable: () => SecureStore.setItemAsync(BIOMETRIC_KEY, "true", DEVICE_ONLY),
  disable: () => SecureStore.deleteItemAsync(BIOMETRIC_KEY),
};
