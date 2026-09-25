/**
 * CRM / search / insured-object helpers (REQ-CRM-001/004, REQ-SRC-001).
 * Pure: node-tested (tests/crm-kyc.test.mjs).
 */

// ------------------------------------------------------------ lead pipeline
/** NEW -> CONTACTED -> QUALIFIED -> QUOTE -> NEGOTIATION -> WON (stored CONVERTED) / LOST. */
export const LEAD_STAGES = ["NEW", "CONTACTED", "QUALIFIED", "QUOTE", "NEGOTIATION", "CONVERTED", "LOST"] as const;
export const OPEN_LEAD_STAGES: string[] = ["NEW", "CONTACTED", "QUALIFIED", "QUOTE", "NEGOTIATION"];
export const LEAD_ACTIVITY_TYPES = ["NOTE", "CALL", "MEETING", "FOLLOW_UP"] as const;
export type LeadActivityType = (typeof LEAD_ACTIVITY_TYPES)[number];

/** Moves offered: only the server's next_statuses; WON is reached by the consented conversion. */
export function leadMoves(status: string, nextStatuses: string[] | null | undefined): string[] {
  if (status === "CONVERTED") return [];
  return (nextStatuses ?? []).filter((s) => s !== "CONVERTED" && s !== status);
}

export const isOpenLead = (status: string) => OPEN_LEAD_STAGES.includes(status);

/** LOST needs a reason. */
export function leadMoveError(to: string | null, lostReason: string): "reason" | null {
  return to === "LOST" && !lostReason.trim() ? "reason" : null;
}

// ------------------------------------------------------------ beneficiaries
export type BeneficiaryDraft = {
  designation: "PRIMARY" | "CONTINGENT";
  full_name: string;
  relationship?: string | null;
  date_of_birth?: string | null;
  allocation_pct: string | number;
  party_id?: string | null;
  revocable?: boolean;
};
export type BeneficiaryIssue = {
  code: "none" | "primaryTotal" | "contingentTotal" | "share" | "name";
  index?: number;
  total?: number;
};

const cents = (v: string | number) => Math.round(Number(String(v).replace(",", ".")) * 100);

/** Mirrors BeneficiaryService: >=1 PRIMARY totalling 100 %; CONTINGENT (if any) 100 %; 0 < share <= 100; a name or party. */
export function validateBeneficiaries(rows: BeneficiaryDraft[]): BeneficiaryIssue[] {
  const issues: BeneficiaryIssue[] = [];
  const totals = { PRIMARY: 0, CONTINGENT: 0 };
  rows.forEach((r, index) => {
    const c = cents(r.allocation_pct);
    if (!Number.isFinite(c) || c <= 0 || c > 10000) issues.push({ code: "share", index });
    else totals[r.designation] += c;
    if (!r.party_id && !r.full_name.trim()) issues.push({ code: "name", index });
  });
  if (!rows.some((r) => r.designation === "PRIMARY")) issues.push({ code: "none" });
  else if (totals.PRIMARY !== 10000) issues.push({ code: "primaryTotal", total: totals.PRIMARY / 100 });
  if (rows.some((r) => r.designation === "CONTINGENT") && totals.CONTINGENT !== 10000)
    issues.push({ code: "contingentTotal", total: totals.CONTINGENT / 100 });
  return issues;
}

export function beneficiaryPayload(rows: BeneficiaryDraft[]) {
  return rows.map((r) => ({
    designation: r.designation,
    ...(r.party_id ? { party_id: r.party_id } : {}),
    full_name: r.full_name.trim() || null,
    relationship: r.relationship || null,
    date_of_birth: r.date_of_birth || null,
    allocation_pct: cents(r.allocation_pct) / 100,
    revocable: r.revocable ?? true,
  }));
}

// ------------------------------------------------------------ global search
export const SEARCH_TYPES = ["customers", "policies", "claims", "quotes", "documents", "vehicles", "risk_assets"] as const;
export type SearchType = (typeof SEARCH_TYPES)[number];
export type SearchHit = {
  type: string;
  id: string;
  title: string;
  subtitle: string | null;
  status: string | null;
  score: number;
  [k: string]: unknown;
};
export type SearchResponse = {
  query: string;
  results: SearchHit[] | Record<string, SearchHit[]>;
  counts?: Record<string, number>;
  searched?: string[];
};

/** Hits grouped by type (SEARCH_TYPES order), best score first. Accepts a flat list or a type-keyed map. */
export function groupSearch(res: SearchResponse | null | undefined): { type: string; hits: SearchHit[] }[] {
  if (!res) return [];
  const flat: SearchHit[] = Array.isArray(res.results)
    ? res.results
    : Object.entries(res.results ?? {}).flatMap(([type, hits]) => (hits ?? []).map((h) => ({ ...h, type: h.type ?? type })));
  const by = new Map<string, SearchHit[]>();
  for (const h of flat) if (h && h.id) by.set(h.type, [...(by.get(h.type) ?? []), h]);
  const order = (t: string) => {
    const i = (SEARCH_TYPES as readonly string[]).indexOf(t);
    return i < 0 ? 99 : i;
  };
  return [...by.entries()]
    .sort((a, b) => order(a[0]) - order(b[0]))
    .map(([type, hits]) => ({ type, hits: [...hits].sort((a, b) => b.score - a.score) }));
}

export type SearchRole = "customer" | "agent" | "broker" | "carrier";

/** Detail screen for a hit in the caller's portal; null when the app has none. */
export function searchHitRoute(hit: Pick<SearchHit, "type" | "id"> & { party_id?: unknown }, role: SearchRole): string | null {
  const party = typeof hit.party_id === "string" && hit.party_id ? `?partyId=${encodeURIComponent(hit.party_id)}` : "";
  switch (hit.type) {
    case "policies":
      return role === "customer" ? `/policy/${hit.id}` : null;
    case "claims":
      return role === "carrier" ? `/carrier/claims/${hit.id}` : role === "customer" ? `/claim/${hit.id}` : null;
    case "quotes":
      return role === "customer" ? `/quotes/${hit.id}` : null;
    case "documents":
      return role === "customer" ? `/documents/${hit.id}` : null;
    case "vehicles":
    case "risk_assets":
      return role === "customer" ? `/assets/${hit.id}` : null;
    case "customers":
      return role === "agent" ? `/agent/clients/${hit.id}${party}` : role === "broker" ? `/broker/clients/${hit.id}${party}` : null;
    default:
      return null;
  }
}

// ------------------------------------------------------------ insured objects
export type RiskAssetType = {
  code: string;
  category: string;
  label: string;
  line_code: string | null;
  schema_available?: boolean;
};

/** Legacy asset type aliases still stored on older assets. */
const LEGACY_ASSET_TYPES: Record<string, string[]> = { MOTOR: ["VEHICLE", "MOTOR", "CAR", "MOTORCYCLE"], HOME: ["PROPERTY", "HOME", "BUILDING"] };

/** Asset type codes that can be insured on a product line (from GET /risk-asset-types), plus legacy aliases. */
export function assetTypesForLine(types: RiskAssetType[] | null | undefined, line: string): string[] {
  const out = new Set<string>(LEGACY_ASSET_TYPES[line] ?? []);
  for (const t of types ?? []) if (t.line_code === line) out.add(t.code.toUpperCase());
  return [...out];
}

const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

/** 409 duplicate vehicle (VehicleDuplicateGuard): the existing asset id from meta, field errors or the message. */
export function duplicateAssetId(e: unknown): string | null {
  const err = e as {
    status?: number;
    message?: string;
    detail?: string;
    fields?: Record<string, string[]>;
    meta?: Record<string, unknown>;
  } | null;
  if (!err || err.status !== 409) return null;
  const meta = err.meta?.existing_asset_id ?? err.meta?.existing_id;
  const texts = [meta, err.detail, err.message, ...Object.values(err.fields ?? {}).flat()];
  for (const t of texts) {
    const m = typeof t === "string" ? t.match(UUID) : null;
    if (m) return m[0];
  }
  return null;
}
