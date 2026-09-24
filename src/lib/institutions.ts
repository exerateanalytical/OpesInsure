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
      hit(query, r.name, r.short_name, r.canonical_id, ...(r.product_families ?? [])),
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
