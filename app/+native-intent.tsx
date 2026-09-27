import { rememberColdStartPath, resolveSystemPath } from "@/lib/navigationContinuity";

/**
 * System URL → route. See src/lib/navigationContinuity.ts: /app prefix and
 * friendly aliases are mapped on a cold start; while the app is running
 * (initial=false) only explicit deep links navigate, anything else returns ""
 * so returning from another app never replaces the current screen.
 */
export function redirectSystemPath({ path, initial }: { path: string; initial: boolean }): string {
  const value = resolveSystemPath(path, initial);
  if (initial) rememberColdStartPath(value);
  return value;
}
