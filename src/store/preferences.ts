import AsyncStorage from "@react-native-async-storage/async-storage";

/**
 * Small device-local preferences for the customer shell. Nothing here is a
 * secret; tokens stay in SecureStore (TokenVault).
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
    const raw = await read(keys.profileExtras);
    if (!raw) return emptyProfileExtras;
    try {
      return { ...emptyProfileExtras, ...(JSON.parse(raw) as Partial<ProfileExtras>) };
    } catch {
      return emptyProfileExtras;
    }
  },
  saveProfileExtras: (extras: ProfileExtras) => write(keys.profileExtras, JSON.stringify(extras)),
  /** Removed on sign-out: personal data must not outlive the session. */
  clearPersonal: async () => {
    await write(keys.profileExtras, null);
    await write(keys.marketingConsent, null);
    await write(keys.pendingOnboarding, null);
  },
};

/** KYC submission notes carry the profile fields the backend has no columns
 * for yet (address, occupation, date of birth, beneficiaries). Max 2000. */
export function profileExtrasToNotes(extras: ProfileExtras) {
  const lines = [
    `address: ${extras.address_line1}`,
    `city: ${extras.city}`,
    `region: ${extras.region}`,
    `occupation: ${extras.occupation}`,
    `date_of_birth: ${extras.date_of_birth}`,
    ...extras.beneficiaries.map(
      (b, i) =>
        `beneficiary_${i + 1}: ${b.full_name} | ${b.relationship} | ${b.date_of_birth} | ${b.share_percent}%`,
    ),
  ];
  return lines.join("\n").slice(0, 2000);
}
