import AsyncStorage from "@react-native-async-storage/async-storage";
import { SecureJson } from "@/security/secureJson";

/**
 * Small device-local preferences for the customer shell. Flags live in
 * AsyncStorage; the profile extras (address, date of birth, beneficiaries)
 * are PII and live in device-only SecureStore chunks (SecureJson).
 */
const keys = {
  onboardingSeen: "opesinsure.onboarding_seen",
  pendingOnboarding: "opesinsure.pending_customer_onboarding",
  marketingConsent: "opesinsure.marketing_consent",
  profileExtras: "opesinsure.profile_extras",
};

const read = async (key: string) => {
  try {
    return await AsyncStorage.getItem(key);
  } catch {
    return null;
  }
};
const write = async (key: string, value: string | null) => {
  try {
    if (value === null) await AsyncStorage.removeItem(key);
    else await AsyncStorage.setItem(key, value);
  } catch {
    // Storage unavailable: the app still works, the flag just is not kept.
  }
};

export type Beneficiary = {
  id: string;
  full_name: string;
  relationship: string;
  date_of_birth: string;
  share_percent: string;
};
export type ProfileExtras = {
  address_line1: string;
  city: string;
  region: string;
  occupation: string;
  date_of_birth: string;
  beneficiaries: Beneficiary[];
  /** Last time the extras were sent to the server with a KYC submission. */
  submitted_at?: string | null;
};
export const emptyProfileExtras: ProfileExtras = {
  address_line1: "",
  city: "",
  region: "",
  occupation: "",
  date_of_birth: "",
  beneficiaries: [],
  submitted_at: null,
};

export const Preferences = {
  onboardingSeen: async () => (await read(keys.onboardingSeen)) === "1",
  markOnboardingSeen: () => write(keys.onboardingSeen, "1"),
  /** Set by sign-up so the first customer landing opens the KYC step. */
  setPendingOnboarding: (pending: boolean) => write(keys.pendingOnboarding, pending ? "1" : null),
  takePendingOnboarding: async () => {
    const pending = (await read(keys.pendingOnboarding)) === "1";
    if (pending) await write(keys.pendingOnboarding, null);
    return pending;
  },
  marketingConsent: async () => (await read(keys.marketingConsent)) === "1",
  setMarketingConsent: (value: boolean) => write(keys.marketingConsent, value ? "1" : "0"),
  profileExtras: async (): Promise<ProfileExtras> => {
    const stored = await SecureJson.read<Partial<ProfileExtras> | null>(keys.profileExtras, null);
    if (stored) return { ...emptyProfileExtras, ...stored };
    // One-time move of the pre-1.3 plain AsyncStorage copy.
    const raw = await read(keys.profileExtras);
    if (!raw) return emptyProfileExtras;
    try {
      const legacy = { ...emptyProfileExtras, ...(JSON.parse(raw) as Partial<ProfileExtras>) };
      await SecureJson.write(keys.profileExtras, legacy).catch(() => undefined);
      await write(keys.profileExtras, null);
      return legacy;
    } catch {
      return emptyProfileExtras;
    }
  },
  saveProfileExtras: async (extras: ProfileExtras) => {
    try {
      await SecureJson.write(keys.profileExtras, extras);
    } catch {
      // Keystore unavailable: the extras just are not kept on this device.
    }
  },
  /** Pre-1.3 device-only copy, dropped once the server profile (customer_profile form) holds it. */
  forgetProfileExtras: async () => {
    await write(keys.profileExtras, null);
    await SecureJson.remove(keys.profileExtras).catch(() => undefined);
  },
  /** Removed on sign-out: personal data must not outlive the session. */
  clearPersonal: async () => {
    await write(keys.profileExtras, null);
    await SecureJson.remove(keys.profileExtras);
    await write(keys.marketingConsent, null);
    await write(keys.pendingOnboarding, null);
  },
};
