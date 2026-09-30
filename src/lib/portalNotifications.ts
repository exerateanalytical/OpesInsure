/**
 * Partner-portal notification deep links (agent, broker, insurer inboxes).
 *
 * A notification's `path` comes from the server; the app only follows it when
 * it is a same-app route of the portal the user is in (or one of the shared
 * staff screens). Anything else — another portal, a customer screen, an
 * external URL, a traversal — is not tappable. No imports (node-tested).
 */
export type NotificationPortal = "agent" | "broker" | "carrier";

/** First path segment under /{portal} that has a screen (app/{portal}/...). */
const PORTAL_SECTIONS: Record<NotificationPortal, readonly string[]> = {
  agent: ["account", "catalogue", "claims", "clients", "commissions", "leads", "notifications", "offline", "onboarding", "pipeline", "policies", "proposals", "quotes", "renewals", "sales", "wallet", "withdrawal", "withdrawals"],
  broker: ["account", "catalogue", "claims", "clients", "commissions", "compliance", "leads", "notifications", "policies", "production", "proposals", "publications", "quotes", "receivables", "renewals", "staff"],
  carrier: ["account", "bordereaux", "claims", "issuance", "notifications", "partners", "payments", "policies", "products", "proposals", "quote-requests", "referrals", "settlement", "settlements"],
};

/** Screens every signed-in staff role may open (declared outside the portal guards). */
const SHARED = ["documents", "support", "quote-comparison", "search"];

const SAFE = /^\/[A-Za-z0-9._~\-/]*(\?[A-Za-z0-9._~\-=&%]*)?$/;

export const portalNotificationTarget = (
  path: string | null | undefined,
  portal: NotificationPortal,
): string | null => {
  const raw = (path ?? "").trim();
  if (!raw || !SAFE.test(raw) || raw.startsWith("//") || raw.includes("..")) return null;
  const [route] = raw.split("?");
  const segments = route!.split("/").filter(Boolean);
  if (segments[0] === portal) {
    if (segments.length === 1) return raw;
    return PORTAL_SECTIONS[portal].includes(segments[1]!) ? raw : null;
  }
  return segments.length > 0 && SHARED.includes(segments[0]!) ? raw : null;
};

/** The list after `ids` were marked read (optimistic update). */
export const markedRead = <T extends { id: string; read: boolean }>(items: readonly T[], ids: "all" | readonly string[]): T[] =>
  items.map((n) => (ids === "all" || ids.includes(n.id) ? { ...n, read: true } : n));
