import { useEffect, useState } from "react";
import { AccessibilityInfo, Platform } from "react-native";
import { colors } from "@/theme/tokens";

/**
 * A11Y-004 high-contrast mode. Follows Android "High contrast text" and iOS
 * "Bold Text"; when either is on, shared controls get stronger borders and
 * darker secondary text. The default theme already meets WCAG AA
 * (tests/contrast.test.mjs); this is the extra step for users who ask for it.
 */
let current = false;
const listeners = new Set<(v: boolean) => void>();
const set = (v: boolean) => {
  if (v === current) return;
  current = v;
  listeners.forEach((l) => l(v));
};

let started = false;
function start() {
  if (started) return;
  started = true;
  const probe = Platform.OS === "android" ? AccessibilityInfo.isHighTextContrastEnabled : AccessibilityInfo.isBoldTextEnabled;
  const event = Platform.OS === "android" ? "highTextContrastChanged" : "boldTextChanged";
  try {
    void probe?.call(AccessibilityInfo).then(set, () => undefined);
    AccessibilityInfo.addEventListener(event as "boldTextChanged", (v: boolean) => set(!!v));
  } catch {
    // Web / unsupported platform: stay on the default (AA) theme.
  }
}

export function useHighContrast(): boolean {
  const [v, setV] = useState(current);
  useEffect(() => {
    start();
    listeners.add(setV);
    setV(current);
    return () => {
      listeners.delete(setV);
    };
  }, []);
  return v;
}

/** Colours swapped in when high contrast is on. */
export const highContrast = {
  border: colors.navy800,
  borderWidth: 2,
  secondaryText: colors.neutral800,
} as const;
