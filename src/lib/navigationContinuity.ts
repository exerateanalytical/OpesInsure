/**
 * Navigation continuity: an authenticated user who leaves the app (camera,
 * mobile-money hand-off, share sheet, app switcher) must come back to the
 * exact screen they left. Pure helpers (no React Native imports) so the rules
 * are unit tested in tests/navigation-continuity.test.mjs.
 *
 * Root cause of the "partner invitation screen after returning" bug (1.3.0):
 * any flip of the session status away from "authenticated" (a re-hydrate
 * setting "booting", a transient network error setting "error") removes every
 * guarded screen from the root Stack. expo-router then falls back to the first
 * declared screen that is still allowed, and after the status flips back the
 * first unguarded declared screen was "(auth)/invitation". Three defences:
 *   1. a re-hydrate while authenticated never leaves "authenticated";
 *   2. `index` is declared first in app/_layout.tsx, so any fallback lands on
 *      the router screen (which sends the user home), never on invitation;
 *   3. a URL event received while the app is already running (initial=false)
 *      that carries no deep link keeps the current screen.
 */

export type ContinuityStatus = "booting" | "anonymous" | "authenticating" | "authenticated" | "error";

/** Status to show while hydrate() runs. A signed-in user stays signed in
 * (silent refresh); only a cold start shows "booting". */
export const hydrateStartStatus = (current: ContinuityStatus): ContinuityStatus =>
  current === "authenticated" ? "authenticated" : "booting";

/** Status after hydrate() failed for a non-credential reason (timeout, DNS,
 * 5xx) and there is no cached bootstrap: keep a live session, otherwise show
 * the offline splash. */
export const statusAfterNetworkFailure = (previous: ContinuityStatus): ContinuityStatus =>
  previous === "authenticated" ? "authenticated" : "error";

/** Paths that are real in-app deep links (opened from email, SMS, QR, web). */
const DEEP_LINK = /^\/(\(auth\)\/(invitation|sign-in)|verify|institutions|policy|claim|payments|notifications|support|quotes|proposals|delivery|documents)(?=\/|\?|#|$)/;

/**
 * Maps an incoming system URL to an expo-router path.
 *
 * Universal/App Links are registered for https://insurance.opesdatacenter.tech
 * with pathPrefix /app, but no route lives under /app: the prefix is stripped
 * so /app/verify opens app/verify.tsx and /app/invitation?token=... opens the
 * invitation screen.
 *
 * With `initial === false` the app is already running (returning from another
 * app). expo-router ignores an empty result, so anything that is not an
 * explicit deep link returns "" and the user stays on the current screen.
 */
export function resolveSystemPath(path: string, initial: boolean): string {
  try {
    let value = String(path ?? "").trim();
    const web = /^https?:\/\/[^/]+(\/.*)?$/i.exec(value);
    if (web) value = web[1] ?? "/";
    else {
      // Custom scheme: opesinsure://invitation?token=… → /invitation?token=…
      const custom = /^[a-z][a-z0-9+.-]*:\/\/(.*)$/i.exec(value);
      if (custom) value = `/${custom[1] ?? ""}`;
    }
    value = value.replace(/^\/{2,}/, "/");
    value = value.replace(/^\/app(?=\/|\?|#|$)/, "") || "/";
    if (!value.startsWith("/")) value = `/${value}`;
    // Friendly aliases used in partner emails / printed certificates.
    value = value
      .replace(/^\/invitation(?=\/|\?|$)/, "/(auth)/invitation")
      .replace(/^\/invitations\/accept(?=\/|\?|$)/, "/(auth)/invitation")
      .replace(/^\/sign-in(?=\/|\?|$)/, "/(auth)/sign-in");
    if (initial) return value;
    return DEEP_LINK.test(value) ? value : "";
  } catch {
    return initial ? "/" : "";
  }
}
