/**
 * GET /mobile/capabilities (ARCH-004/005) and per-record `allowed_actions`.
 * Advisory only: the server authorizes every call. These helpers can HIDE an
 * entry the server says is unavailable; when the payload is absent (older
 * backend, offline, error) every check returns the caller's existing answer.
 * No imports (node-tested).
 */
export type ModuleCapability = { view: boolean; actions: string[] };
export type Capabilities = { data_scope: string | null; modules: Record<string, ModuleCapability> };

export function parseCapabilities(raw: unknown): Capabilities | null {
  const r = raw as { data_scope?: unknown; modules?: unknown } | null;
  if (!r || typeof r !== "object" || !r.modules || typeof r.modules !== "object") return null;
  const modules: Record<string, ModuleCapability> = {};
  for (const [name, value] of Object.entries(r.modules as Record<string, unknown>)) {
    const v = value as { view?: unknown; actions?: unknown } | null;
    if (!v || typeof v !== "object") continue;
    modules[name] = {
      view: v.view === true,
      actions: Array.isArray(v.actions) ? v.actions.filter((a): a is string => typeof a === "string") : [],
    };
  }
  return { data_scope: typeof r.data_scope === "string" ? r.data_scope : null, modules };
}

/** true / false when the server said so; null when it said nothing (fall back). */
export function capabilityAllows(caps: Capabilities | null | undefined, module: string, action?: string): boolean | null {
  const m = caps?.modules[module];
  if (!m) return null;
  if (!action) return m.view;
  return m.view && m.actions.includes(action);
}

/** Existing gate AND the capability, the capability only narrowing. */
export const gateWithCapability = (
  fallback: boolean,
  caps: Capabilities | null | undefined,
  module: string | undefined,
  action?: string,
) => fallback && (module ? capabilityAllows(caps, module, action) !== false : true);

/**
 * Record-level `allowed_actions`: when the field is present (even empty) an
 * action is shown only if listed; when absent the screen's current rule stands.
 */
export function allowedAction(
  record: object | null | undefined,
  action: string,
  fallback: boolean,
): boolean {
  const list = (record as { allowed_actions?: unknown } | null | undefined)?.allowed_actions;
  if (!Array.isArray(list)) return fallback;
  return fallback && list.includes(action);
}

/** Partner-shell route segment (/agent/<seg>, /broker/<seg>, /carrier/<seg>) -> capability module. */
const SEGMENT_MODULE: Record<string, string> = {
  leads: "leads",
  clients: "clients",
  quotes: "quotes",
  policies: "policies",
  proposals: "proposals",
  claims: "claims",
  renewals: "renewals",
  wallet: "commissions",
  commissions: "commissions",
  staff: "staff",
  referrals: "referrals",
  issuance: "issuance",
  payments: "payments",
  settlements: "settlements",
  bordereaux: "bordereaux",
  "quote-requests": "quotes",
};

export function moduleForHref(href: string | undefined): string | undefined {
  if (!href) return undefined;
  const parts = href.split("?")[0]!.split("/").filter(Boolean);
  if (!["agent", "broker", "carrier"].includes(parts[0] ?? "")) return undefined;
  return SEGMENT_MODULE[parts[1] ?? ""];
}

/** Menu / KPI link visibility: hidden only when the server reports the module as not viewable. */
export const hrefVisible = (caps: Capabilities | null | undefined, href: string | undefined) =>
  gateWithCapability(true, caps, moduleForHref(href));
