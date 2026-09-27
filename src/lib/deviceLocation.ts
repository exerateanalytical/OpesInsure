/**
 * Device location for form autofill (town, region, GPS).
 *
 * expo-location is a native module older APKs do not contain: it is detected
 * with requireOptionalNativeModule and only then loaded, so those builds just
 * skip autofill (OTA-safe). On web, navigator.geolocation gives coordinates
 * (no reverse geocoder there, so town/region stay manual).
 *
 * Preferences are device-local: a global switch (Settings → Privacy, default
 * on) and a per-form opt-out ("Don't use my location").
 */
import { Alert, Platform } from "react-native";
import { requireOptionalNativeModule } from "expo";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { ClaimsCompletionApi } from "@/api/client";
import type { DeviceFix } from "@/lib/locationMatch";

type LocationModule = typeof import("expo-location");
const native: LocationModule | null = (() => {
  if (Platform.OS === "web") return null;
  try {
    if (!requireOptionalNativeModule("ExpoLocation")) return null;
    // eslint-disable-next-line @typescript-eslint/no-require-imports -- load only when the native module exists
    return require("expo-location") as LocationModule;
  } catch {
    return null;
  }
})();

const KEY_GLOBAL = "opesinsure.location.autofill";
const KEY_ASKED = "opesinsure.location.explained";
const KEY_FORM = (form: string) => `opesinsure.location.optout.${form}`;
const FIX_TTL_MS = 5 * 60 * 1000;
const TIMEOUT_MS = 12000;

const get = async (k: string) => {
  try {
    return await AsyncStorage.getItem(k);
  } catch {
    return null;
  }
};
const set = async (k: string, v: string | null) => {
  try {
    if (v === null) await AsyncStorage.removeItem(k);
    else await AsyncStorage.setItem(k, v);
  } catch {
    // storage unavailable: preference not kept
  }
};

export const LocationPrefs = {
  /** Global switch; default on. */
  enabled: async () => (await get(KEY_GLOBAL)) !== "0",
  setEnabled: (on: boolean) => set(KEY_GLOBAL, on ? "1" : "0"),
  formOptedOut: async (form: string) => (await get(KEY_FORM(form))) === "1",
  setFormOptedOut: (form: string, out: boolean) => set(KEY_FORM(form), out ? "1" : null),
};

/** True when this build/platform can read a position at all. */
export function locationSupported(): boolean {
  if (native) return true;
  return Platform.OS === "web" && typeof navigator !== "undefined" && !!navigator.geolocation;
}

let cache: { at: number; fix: DeviceFix } | null = null;
let running: Promise<DeviceFix | null> | null = null;

function withTimeout<T>(p: Promise<T>, ms: number): Promise<T | null> {
  return Promise.race([p, new Promise<null>((r) => setTimeout(() => r(null), ms))]);
}

export type LocationCopy = { title: string; body: string; allow: string; notNow: string };

/** Explains why once before the OS prompt; resolves true when the user agrees. */
function explainOnce(copy: LocationCopy): Promise<boolean> {
  return new Promise((resolve) => {
    Alert.alert(copy.title, copy.body, [
      { text: copy.notNow, style: "cancel", onPress: () => resolve(false) },
      { text: copy.allow, onPress: () => resolve(true) },
    ], { cancelable: true, onDismiss: () => resolve(false) });
  });
}

async function nativeFix(copy: LocationCopy, interactive: boolean): Promise<DeviceFix | null> {
  const L = native!;
  let perm = await L.getForegroundPermissionsAsync();
  if (perm.status !== "granted") {
    if (!perm.canAskAgain) return null;
    const asked = (await get(KEY_ASKED)) === "1";
    // Automatic prefill asks politely once; later only an explicit "Use my location" asks again.
    if (asked && !interactive) return null;
    await set(KEY_ASKED, "1");
    if (!(await explainOnce(copy))) return null;
    perm = await L.requestForegroundPermissionsAsync();
    if (perm.status !== "granted") return null;
  }
  const pos = await withTimeout(L.getCurrentPositionAsync({ accuracy: L.Accuracy.Balanced }), TIMEOUT_MS)
    ?? (await L.getLastKnownPositionAsync({ maxAge: 10 * 60 * 1000 }));
  if (!pos) return null;
  const { latitude, longitude, accuracy } = pos.coords;
  let place: DeviceFix["place"] = null;
  try {
    const found = await withTimeout(L.reverseGeocodeAsync({ latitude, longitude }), 8000);
    const p = found?.[0];
    if (p) place = { city: p.city, district: p.district, subregion: p.subregion, region: p.region, country: p.country, isoCountryCode: p.isoCountryCode };
  } catch {
    // offline geocoder: coordinates only
  }
  return { latitude, longitude, accuracy, place };
}

function webFix(): Promise<DeviceFix | null> {
  return new Promise((resolve) => {
    try {
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy, place: null }),
        () => resolve(null),
        { enableHighAccuracy: false, timeout: TIMEOUT_MS, maximumAge: FIX_TTL_MS },
      );
    } catch {
      resolve(null);
    }
  });
}

/**
 * Current position + reverse-geocoded place, or null (unsupported, denied,
 * timed out). `interactive` is true when the user pressed "Use my location".
 */
export async function readDeviceLocation(copy: LocationCopy, interactive = false): Promise<DeviceFix | null> {
  if (cache && Date.now() - cache.at < FIX_TTL_MS) return cache.fix;
  if (!locationSupported()) return null;
  if (running) return running;
  running = (async () => {
    try {
      const fix = native ? await nativeFix(copy, interactive) : await webFix();
      if (fix) cache = { at: Date.now(), fix };
      return fix;
    } catch {
      return null;
    }
  })().finally(() => {
    running = null;
  });
  return running;
}

/**
 * Attaches the incident position to a claim (PUT /mobile/claims/{id}/incident
 * accepts latitude/longitude; POST /mobile/claims does not). Best effort: a
 * failure never blocks the claim.
 */
export async function attachClaimCoordinates(claimId: string, fix: Pick<DeviceFix, "latitude" | "longitude"> | null) {
  if (!fix) return;
  try {
    await ClaimsCompletionApi.saveIncident(claimId, { latitude: Number(fix.latitude.toFixed(6)), longitude: Number(fix.longitude.toFixed(6)) });
  } catch {
    // coordinates are optional
  }
}
