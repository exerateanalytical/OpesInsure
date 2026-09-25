import { create } from "zustand";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { SettingsApi, type UserSettings } from "@/api/client";

/**
 * Display time zone (REQ-TMP-003). GET /me/settings answers
 * effective_display_timezone: the user's own pick, else the organisation's
 * business zone (Africa/Douala by default). Every date the app formats uses
 * it; the last known value is kept for offline launches.
 */
export const DEFAULT_TIMEZONE = "Africa/Douala";
const KEY = "opesinsure.display_timezone";

type TimezoneState = {
  /** Zone used for display. */
  timezone: string;
  /** The user's own pick (null = organisation default). */
  own: string | null;
  business: string;
  load: () => Promise<void>;
  save: (timezone: string | null) => Promise<void>;
  reset: () => void;
};

/** A zone Intl can format with (Hermes rejects unknown IANA names). */
export function usableTimezone(tz: string | null | undefined): string {
  if (!tz) return DEFAULT_TIMEZONE;
  try {
    new Intl.DateTimeFormat("en", { timeZone: tz }).format(0);
    return tz;
  } catch {
    return DEFAULT_TIMEZONE;
  }
}

const apply = (s: UserSettings) => ({
  timezone: usableTimezone(s.effective_display_timezone ?? s.display_timezone ?? s.business_timezone),
  own: s.display_timezone ?? null,
  business: s.business_timezone || DEFAULT_TIMEZONE,
});

export const useTimezone = create<TimezoneState>((set) => ({
  timezone: DEFAULT_TIMEZONE,
  own: null,
  business: DEFAULT_TIMEZONE,
  async load() {
    try {
      const cached = await AsyncStorage.getItem(KEY);
      if (cached) set({ timezone: usableTimezone(cached) });
    } catch {
      // no cache
    }
    try {
      const next = apply(await SettingsApi.me());
      set(next);
      AsyncStorage.setItem(KEY, next.timezone).catch(() => undefined);
    } catch {
      // offline or endpoint unavailable: keep the cached / default zone
    }
  },
  async save(timezone) {
    const next = apply(await SettingsApi.updateMe(timezone));
    set(next);
    AsyncStorage.setItem(KEY, next.timezone).catch(() => undefined);
  },
  reset() {
    set({ timezone: DEFAULT_TIMEZONE, own: null, business: DEFAULT_TIMEZONE });
    AsyncStorage.removeItem(KEY).catch(() => undefined);
  },
}));
