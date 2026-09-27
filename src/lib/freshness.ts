/**
 * App-wide data freshness contract (REF-001 / REF-002).
 *
 * Every operational screen should: show when its data was last loaded,
 * revalidate on focus, flag data older than its domain's stale target, and
 * poll only while the app is in the foreground and the screen is focused.
 * Push notifications (see src/notifications/push.ts) trigger an immediate
 * reload where available; polling is the fallback, sized to the SLA below.
 */
export type FreshnessDomain =
  | "payments"
  | "claims_queue"
  | "underwriting_queue"
  | "quote_requests"
  | "security"
  | "policies"
  | "reference";

export type FreshnessPolicy = {
  /** Data older than this is shown as stale. */
  staleAfterMs: number;
  /** Foreground polling interval while focused; 0 = focus/pull-to-refresh only. */
  pollMs: number;
};

const MIN = 60_000;

export const FRESHNESS: Record<FreshnessDomain, FreshnessPolicy> = {
  // Payment status waits on the gateway callback; the server is the only source of "paid".
  payments: { staleAfterMs: 1 * MIN, pollMs: 30_000 },
  claims_queue: { staleAfterMs: 5 * MIN, pollMs: 2 * MIN },
  underwriting_queue: { staleAfterMs: 5 * MIN, pollMs: 2 * MIN },
  quote_requests: { staleAfterMs: 5 * MIN, pollMs: 2 * MIN },
  security: { staleAfterMs: 5 * MIN, pollMs: 0 },
  policies: { staleAfterMs: 30 * MIN, pollMs: 0 },
  reference: { staleAfterMs: 24 * 60 * MIN, pollMs: 0 },
};

export function isStale(loadedAt: number | null, domain: FreshnessDomain, now = Date.now()): boolean {
  return loadedAt !== null && now - loadedAt > FRESHNESS[domain].staleAfterMs;
}
