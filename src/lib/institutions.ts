/** Pure helpers for the official DGTCFM/MINFI 2026 institution register
 * (GET /public/institutions). No imports so node tests can load it. */

export type Branch = "IARD" | "LIFE";
export type BranchFilter = "all" | Branch;

type InsurerLike = {
  name: string;
  short_name?: string | null;
  branch?: string | null;
  product_families?: string[];
  is_official_register?: boolean;
  canonical_id?: string | null;
};
type BrokerLike = {
  name: string;
  city: string | null;
  regulator_number?: number | null;
  canonical_id?: string | null;
};

/** i18n key of the mandatory source note on every register screen. */
export const REGISTER_SOURCE_KEY = "registerSource" as const;

const fold = (value: string) =>
  value.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();

const hit = (query: string, ...values: (string | null | undefined)[]) => {
  const q = fold(query);
  return !q || values.some((v) => !!v && fold(v).includes(q));
};

export function filterInsurers<T extends InsurerLike>(rows: T[], branch: BranchFilter, query: string): T[] {
  return rows.filter(
    (r) =>
      (branch === "all" || r.branch === branch) &&
      hit(query, r.name, r.short_name, r.canonical_id, ...(r.product_families ?? []), ...insurerCities(r)),
  );
}

export function filterBrokers<T extends BrokerLike>(rows: T[], query: string): T[] {
  const q = query.trim();
  if (/^\d+$/.test(q)) return rows.filter((r) => r.regulator_number === Number(q));
  return rows.filter((r) => hit(q, r.name, r.city, r.canonical_id));
}

/**
 * Brokers the platform features (owner, 2026-09-28). The server `featured` flag wins once
 * GET /public/institutions sends it; until then these register names are featured.
 */
export const FEATURED_BROKER_NAMES = ["ASSUR EXPERT D&G SARL"] as const;

const squash = (value: string) => fold(value).replace(/[^a-z0-9&]/g, "");

export function isFeaturedBroker(row: { name: string; featured?: boolean | null }): boolean {
  if (typeof row.featured === "boolean") return row.featured;
  const n = squash(row.name);
  return FEATURED_BROKER_NAMES.some((f) => squash(f) === n);
}

/** Featured brokers first; everything else keeps its incoming (regulator) order. */
export function featuredFirst<T extends { name: string; featured?: boolean | null }>(rows: T[]): T[] {
  return [...rows.filter(isFeaturedBroker), ...rows.filter((r) => !isFeaturedBroker(r))];
}

type AffiliationProduct = { id: string; name: string; line_code: string; carrier_id?: string | null; carrier_name?: string | null };

/** Products a broker offers, grouped by line (lines alphabetical, products by name). */
export function productsByLine<T extends AffiliationProduct>(products: T[] | null | undefined): { line: string; products: T[] }[] {
  const groups = new Map<string, T[]>();
  for (const p of products ?? []) groups.set(p.line_code, [...(groups.get(p.line_code) ?? []), p]);
  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([line, list]) => ({ line, products: [...list].sort((x, y) => x.name.localeCompare(y.name)) }));
}

export function registerCounts(rows: InsurerLike[]) {
  const official = rows.filter((r) => r.is_official_register);
  return {
    total: official.length,
    IARD: official.filter((r) => r.branch === "IARD").length,
    LIFE: official.filter((r) => r.branch === "LIFE").length,
  };
}

// --- Institutional directory (HQ, contacts, branches, verification) -------
// The API shape is not final: every reader below is defensive and returns
// empty values so screens can hide sections that are missing.

export type DirectoryBranch = {
  name: string | null;
  type: string | null;
  city: string | null;
  address: string | null;
  phone: string | null;
};
export type DirectoryHq = { city: string | null; address: string | null; po_box: string | null };
export type VerificationKey =
  | "verificationVerified"
  | "verificationPartial"
  | "verificationHqBranchesPending"
  | "verificationGroupNetwork";
export type VerificationBadge = {
  key: VerificationKey;
  tone: "success" | "warning" | "info";
  /** Admin-editable label from the API ({en, fr}); preferred over `key`. */
  label: { en?: string | null; fr?: string | null } | null;
};
export type InsurerDirectory = {
  hq: DirectoryHq | null;
  phones: string[];
  emails: string[];
  website: string | null;
  branches: DirectoryBranch[];
  verification: VerificationBadge | null;
  sources: string[];
};

const str = (v: unknown): string | null =>
  typeof v === "string" && v.trim() ? v.trim() : typeof v === "number" ? String(v) : null;
const strList = (v: unknown): string[] =>
  Array.isArray(v) ? v.map(str).filter((s): s is string => !!s) : str(v) ? [str(v)!] : [];
const obj = (v: unknown): Record<string, unknown> | null =>
  v && typeof v === "object" && !Array.isArray(v) ? (v as Record<string, unknown>) : null;
const uniq = (xs: string[]) => Array.from(new Set(xs));

export function verificationBadge(status: unknown, labelRaw?: unknown): VerificationBadge | null {
  const s = str(status)?.toUpperCase();
  const l = obj(labelRaw);
  const label = l && (str(l.en) || str(l.fr)) ? { en: str(l.en), fr: str(l.fr) } : null;
  if (!s) return label ? { key: "verificationVerified", tone: "info", label } : null;
  if (s === "VERIFIED") return { key: "verificationVerified", tone: "success", label };
  if (s.startsWith("VERIFIED_HQ")) return { key: "verificationHqBranchesPending", tone: "info", label };
  if (s.startsWith("VERIFIED_NETWORK")) return { key: "verificationGroupNetwork", tone: "success", label };
  if (s.startsWith("PARTIAL")) return { key: "verificationPartial", tone: "warning", label };
  return label ? { key: "verificationVerified", tone: "info", label } : null;
}

/** Badge text: the API label in the user's language, else the built-in copy. */
export function verificationText(
  badge: VerificationBadge,
  language: "en" | "fr",
  t: (key: VerificationKey) => string,
): string {
  const api = badge.label?.[language] ?? badge.label?.en ?? badge.label?.fr;
  return api && api.trim() ? api.trim() : t(badge.key);
}

/** Normalise an insurer's directory fields (nested `contacts` or top-level). */
export function readDirectory(row: unknown): InsurerDirectory {
  const r = obj(row) ?? {};
  const contacts = obj(r.contacts) ?? {};
  // Final API key is `head_office`; `hq` was the draft name.
  const hqRaw = obj(r.head_office) ?? obj(r.hq);
  const hq: DirectoryHq | null = hqRaw
    ? {
        city: str(hqRaw.city),
        address: str(hqRaw.address),
        po_box: str(hqRaw.po_box) ?? str(contacts.po_box),
      }
    : str(contacts.po_box)
      ? { city: null, address: null, po_box: str(contacts.po_box) }
      : null;
  const website = str(contacts.website) ?? str(r.website);
  const branches = (Array.isArray(r.branches) ? r.branches : [])
    .map(obj)
    .filter((b): b is Record<string, unknown> => !!b)
    .map((b) => ({
      name: str(b.name),
      type: str(b.type),
      city: str(b.city),
      address: str(b.address),
      phone: str(b.phone),
    }))
    .filter((b) => b.name || b.address || b.phone);
  const sources = (Array.isArray(r.sources) ? r.sources : [])
    .map((s) => str(s) ?? str(obj(s)?.url) ?? str(obj(s)?.name))
    .filter((s): s is string => !!s);
  return {
    hq: hq && (hq.city || hq.address || hq.po_box) ? hq : null,
    phones: uniq([...strList(contacts.phones), ...strList(r.phones), ...strList(r.phone)]),
    emails: uniq([...strList(contacts.emails), ...strList(r.emails), ...strList(r.email)]),
    website: website && !/^https?:\/\//i.test(website) ? `https://${website}` : website,
    branches,
    verification: verificationBadge(r.verification_status, r.verification_label),
    sources,
  };
}

/** tel: URL with spaces/dots stripped; null when nothing dialable remains.
 * Short codes ("8033") are dialled as-is: never prefixed with +237. */
export function telUrl(phone: string): string | null {
  const digits = phone.replace(/[^\d+]/g, "");
  return digits ? `tel:${digits}` : null;
}

/** Branches grouped by city (unknown city last), cities sorted alphabetically. */
export function groupBranchesByCity(branches: DirectoryBranch[]): { city: string | null; branches: DirectoryBranch[] }[] {
  const map = new Map<string | null, DirectoryBranch[]>();
  for (const b of branches) {
    const k = b.city ?? null;
    map.set(k, [...(map.get(k) ?? []), b]);
  }
  return Array.from(map.entries())
    .sort(([a], [b]) => (a === null ? 1 : b === null ? -1 : a.localeCompare(b)))
    .map(([city, list]) => ({ city, branches: list }));
}

/** Every city an insurer is present in (HQ, legacy city, branches). */
export function insurerCities(row: unknown): string[] {
  const r = obj(row) ?? {};
  const d = readDirectory(row);
  return uniq(
    [d.hq?.city ?? null, str(r.city), ...d.branches.map((b) => b.city)].filter((c): c is string => !!c),
  );
}

/** Sorted list of distinct cities across insurers (for the city filter). */
export function directoryCities(rows: unknown[]): string[] {
  return uniq(rows.flatMap(insurerCities)).sort((a, b) => a.localeCompare(b));
}

export function filterByCity<T>(rows: T[], city: string | null): T[] {
  if (!city) return rows;
  const c = fold(city);
  return rows.filter((r) => insurerCities(r).some((x) => fold(x) === c));
}

// --- Profile pages (app/institutions/insurer|broker/[id]) ----------------

/** Detail route for a directory row type; a broker id opened on the insurer route is redirected. */
export function institutionRoute(type: string | null | undefined): "/institutions/insurer/[id]" | "/institutions/broker/[id]" {
  return type === "broker" ? "/institutions/broker/[id]" : "/institutions/insurer/[id]";
}

/** GET /public/institutions/{id} answered 404 (unknown or no longer listed). */
export function isNotFound(error: unknown): boolean {
  return (obj(error)?.status ?? null) === 404;
}

/** institution_offices.office_type: HEAD_OFFICE | DIRECT_BRANCH (anything else reads as a branch). */
export function officeKind(type: string | null | undefined): "head" | "branch" | null {
  const t = (type ?? "").trim().toUpperCase();
  if (!t) return null;
  return t === "HEAD_OFFICE" ? "head" : "branch";
}

/** Branch offices only: the head office listed among the offices is not a branch. */
export function branchOffices(branches: DirectoryBranch[]): DirectoryBranch[] {
  return branches.filter((b) => officeKind(b.type) !== "head");
}

/** Head-office street line with the city appended when the address does not already name it. */
export function hqAddress(hq: DirectoryHq | null): string | null {
  if (!hq) return null;
  if (!hq.address) return hq.city;
  return hq.city && !fold(hq.address).includes(fold(hq.city)) ? `${hq.address}, ${hq.city}` : hq.address;
}

/** Google Maps search URL for the head office (opens the Maps app on Android and iOS). */
export function directionsUrl(hq: DirectoryHq | null): string | null {
  const line = hqAddress(hq);
  return line ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${line}, Cameroun`)}` : null;
}

/** Host name without "www." for showing a URL ("cm.sanlamallianz.com"). */
export function hostLabel(url: string): string {
  const m = /^https?:\/\/([^/?#:]+)/i.exec(url.trim());
  return m?.[1] ? m[1].replace(/^www\./i, "").toLowerCase() : url.trim();
}

/** Directory sources as tappable links (http/https) or plain labels, de-duplicated. */
export function sourceLinks(sources: string[]): { label: string; url: string | null }[] {
  const seen = new Set<string>();
  const out: { label: string; url: string | null }[] = [];
  for (const raw of sources) {
    const s = raw.trim();
    if (!s || seen.has(s)) continue;
    seen.add(s);
    const url = /^https?:\/\/[^\s]+$/i.test(s) ? s : null;
    out.push({ label: url ? hostLabel(url) : s, url });
  }
  return out;
}

/** Broker licence against today's date (YYYY-MM-DD, Douala); null when no expiry is published. */
export function licenceState(expiresOn: string | null | undefined, todayIso: string): "valid" | "expired" | null {
  const d = str(expiresOn)?.slice(0, 10);
  if (!d || !/^\d{4}-\d{2}-\d{2}$/.test(d)) return null;
  return d < todayIso.slice(0, 10) ? "expired" : "valid";
}

/**
 * What "Get a quote" does on a public profile: signed-out visitors sign in first, customers
 * start the quote flow, other workspaces (agents, brokers, carrier staff) do not get the
 * customer quote CTA (their quote screens live in their own portal).
 */
export function quoteEntry(status: string | null | undefined, portal: string | null | undefined): "sign-in" | "quote" | null {
  if (status !== "authenticated") return "sign-in";
  return portal === "customer" ? "quote" : null;
}
