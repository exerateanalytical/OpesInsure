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
