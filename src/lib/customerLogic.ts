/**
 * Pure helpers for the customer shell (no imports, so node:test can load
 * them directly): deep-link validation, countdown formatting, renewal
 * windows and lockout parsing.
 */

/** Route prefixes a notification may open. Anything else is ignored, so a
 * crafted push payload cannot send the user to an arbitrary screen or URL. */
const ALLOWED_PREFIXES = [
  "/policy/",
  "/policies",
  "/claim/",
  "/claims",
  "/payments",
  "/payment",
  "/wallet",
  "/quotes",
  "/proposals",
  "/documents/",
  "/notifications",
  "/support",
  "/services",
  "/delivery/",
  "/onboarding/kyc",
  "/account/",
  "/confirmation",
  "/(customer)/(tabs)",
];

const TAB_ALIASES: Record<string, string> = {
  "/policies": "/(customer)/(tabs)/policies",
  "/claims": "/(customer)/(tabs)/claims",
  "/home": "/(customer)/(tabs)",
  "/explore": "/(customer)/(tabs)/explore",
  "/profile": "/(customer)/(tabs)/profile",
};

/**
 * Turns a notification's `path` / `target` / `url` / `deep_link` into an
 * in-app route, or null. Accepts app paths, `opesinsure://…` links and the
 * verified `https://insurance.opesdatacenter.tech/app/…` links.
 */
export function resolveNotificationTarget(data: unknown): string | null {
  if (!data || typeof data !== "object") return null;
  const record = data as Record<string, unknown>;
  const raw = [record.path, record.target, record.deep_link, record.url].find(
    (v) => typeof v === "string" && v.length > 0,
  ) as string | undefined;
  if (!raw) return null;
  let path = raw.trim();
  if (path.startsWith("opesinsure://")) path = `/${path.slice("opesinsure://".length)}`;
  else if (path.startsWith("https://insurance.opesdatacenter.tech/app/"))
    path = path.slice("https://insurance.opesdatacenter.tech/app".length);
  else if (/^[a-z][a-z0-9+.-]*:/i.test(path)) return null;
  if (!path.startsWith("/") || path.startsWith("//") || path.includes("..")) return null;
  path = path.replace(/\/+$/, "") || "/";
  const [pathname] = path.split(/[?#]/);
  if (!pathname) return null;
  if (TAB_ALIASES[pathname]) return TAB_ALIASES[pathname];
  return ALLOWED_PREFIXES.some((prefix) => pathname === prefix.replace(/\/$/, "") || pathname.startsWith(prefix))
    ? path
    : null;
}

/** 65 → "1:05". Negative values clamp to 0:00. */
export function formatCountdown(seconds: number) {
  const s = Math.max(0, Math.floor(seconds));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
}

/** Days until an ISO date (ceil), relative to `now`. */
export function daysUntil(iso: string | null | undefined, now: Date = new Date()) {
  if (!iso) return null;
  const at = new Date(iso).getTime();
  if (Number.isNaN(at)) return null;
  return Math.ceil((at - now.getTime()) / 86_400_000);
}

/** An active policy whose cover ends within `window` days (default 30). */
export function isRenewalDue(
  policy: { status?: string | null; coverage_ends_at?: string | null },
  now: Date = new Date(),
  window = 30,
) {
  const status = (policy.status ?? "").toUpperCase();
  if (status !== "ACTIVE" && status !== "EXPIRING") return false;
  const days = daysUntil(policy.coverage_ends_at, now);
  return days !== null && days >= 0 && days <= window;
}

/** True for the server's lockout / throttle answers (429, 423 or a locked code). */
export function isLockout(error: unknown) {
  if (!error || typeof error !== "object") return false;
  const e = error as { status?: number; code?: string };
  return (
    e.status === 429 ||
    e.status === 423 ||
    /LOCK|TOO_MANY|RATE_LIMIT|THROTTL/i.test(e.code ?? "")
  );
}

/** Seconds to wait after a lockout; falls back to 60 when no Retry-After. */
export function lockoutSeconds(error: unknown, fallback = 60) {
  const retry = (error as { retryAfter?: number } | null)?.retryAfter;
  return typeof retry === "number" && retry > 0 ? Math.ceil(retry) : fallback;
}

/** Filters a list by a free-text query over the given fields. */
export function matchesQuery(query: string, ...fields: (string | null | undefined)[]) {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  return fields.some((f) => (f ?? "").toLowerCase().includes(q));
}
