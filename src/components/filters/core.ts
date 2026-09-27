/**
 * Pure filtering core (FLT-001..006): no React / React Native imports so the
 * rules are unit-tested in node (tests/list-filters.test.mjs) and shared by
 * every list screen. The UI layer (index.tsx, FiltersSheet) re-exports these.
 *
 * Standard for every list:
 * - search is case- and accent-insensitive ("Societe" finds "Société") and
 *   every word must match somewhere in the row ("allianz auto");
 * - OR inside a section, AND across sections, AND with the search;
 * - single-choice sections (sort, period) at their first option are inactive;
 * - totals/KPIs are computed from the filtered rows, never the raw page.
 */

export type FilterOptionCore = { value: string; label: string };
export type FilterSectionCore = { key: string; title?: string; options: FilterOptionCore[]; single?: boolean; kind?: "period" | "sort" };
export type FilterValues = Record<string, string[]>;
export type Matchers<T> = Record<string, (row: T, value: string) => boolean>;

/** Lower-case, accents removed, whitespace collapsed ("  Société  Générale" -> "societe generale"). */
export function normalizeText(s: unknown): string {
  if (s === null || s === undefined) return "";
  return String(s)
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[’']/g, "'")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

/** True when every word of `query` appears in at least one field (accent/case-insensitive). Empty query matches. */
export function matchesText(query: string, fields: readonly unknown[]): boolean {
  const words = normalizeText(query).split(" ").filter(Boolean);
  if (!words.length) return true;
  const hay = fields.map(normalizeText).filter(Boolean).join(" \u0001 ");
  return words.every((w) => hay.includes(w));
}

/** Number of active choices, ignoring single-choice sections at their default (first option). */
export function activeFilterCount(values: FilterValues, sections: FilterSectionCore[]): number {
  return sections.reduce((n, s) => {
    const v = values[s.key] ?? [];
    if (s.single) return n + (v[0] && v[0] !== s.options[0]?.value ? 1 : 0);
    return n + v.filter((x) => x && x !== "ALL").length;
  }, 0);
}

/** Empty selection for every section (single-choice sections go back to their first option). */
export function emptyFilters(sections: FilterSectionCore[]): FilterValues {
  return Object.fromEntries(sections.map((s) => [s.key, s.single && s.options[0] ? [s.options[0].value] : []]));
}

/** Selection with `value` removed from `key` (single sections fall back to their default). */
export function removeFilter(values: FilterValues, section: FilterSectionCore, value: string): FilterValues {
  return {
    ...values,
    [section.key]: section.single ? [section.options[0]?.value ?? ""] : (values[section.key] ?? []).filter((v) => v !== value),
  };
}

/**
 * Rows matching the search (any haystack field, accent-insensitive) and every
 * section with a selection (OR inside a section, AND across sections).
 * Sections without a matcher (sort, server-only) are ignored here.
 */
export function applyFilters<T>(rows: readonly T[], values: FilterValues, matchers: Matchers<T>, text = "", haystack?: (row: T) => readonly unknown[]): T[] {
  const q = normalizeText(text);
  return rows.filter((row) => {
    if (q && haystack && !matchesText(q, haystack(row))) return false;
    return Object.entries(values).every(([key, chosen]) => {
      const m = matchers[key];
      const vs = (chosen ?? []).filter((v) => v && v !== "ALL");
      if (!m || !vs.length) return true;
      return vs.some((v) => m(row, v));
    });
  });
}

/** Distinct options from rows, sorted by label (accent-aware). */
export function optionsFrom<T>(rows: readonly T[], pick: (row: T) => { value: string | null | undefined; label?: string | null } | null): FilterOptionCore[] {
  const seen = new Map<string, FilterOptionCore>();
  for (const r of rows) {
    const o = pick(r);
    if (o?.value && !seen.has(o.value)) seen.set(o.value, { value: o.value, label: o.label || o.value });
  }
  return [...seen.values()].sort((a, b) => a.label.localeCompare(b.label, "fr", { sensitivity: "base" }));
}

/** Query string for server-side filtering (`status=a&status=b&q=...`). Empty when nothing is chosen. */
export function filterQuery(values: FilterValues, text = "", sections?: FilterSectionCore[]): string {
  const qs = new URLSearchParams();
  if (text.trim()) qs.set("q", text.trim());
  for (const [k, vs] of Object.entries(values)) {
    const s = sections?.find((x) => x.key === k);
    for (const v of vs ?? []) {
      if (!v || v === "ALL") continue;
      if (s?.single && v === s.options[0]?.value) continue;
      if (s?.kind === "period") {
        const r = periodRange(v);
        if (r) {
          qs.set(`${k}_from`, r.from.toISOString());
          qs.set(`${k}_to`, r.to.toISOString());
        }
        continue;
      }
      qs.append(k, v);
    }
  }
  const out = qs.toString();
  return out ? `?${out}` : "";
}

/** `?f_status=DUE,OVERDUE&q=...` -> initial selection (KPI deep links). */
export function filtersFromParams(params: Record<string, string | string[] | undefined>): { values: FilterValues; text?: string } | undefined {
  const values: FilterValues = {};
  for (const [k, v] of Object.entries(params)) {
    if (!k.startsWith("f_") || v == null) continue;
    values[k.slice(2)] = (Array.isArray(v) ? v : String(v).split(",")).filter(Boolean);
  }
  const text = typeof params.q === "string" ? params.q : undefined;
  return Object.keys(values).length || text ? { values, text } : undefined;
}

// ---------------------------------------------------------------- periods

/** Default business timezone (REQ-TMP-003). Cameroon is UTC+1 all year (no DST). */
export const DEFAULT_TZ = "Africa/Douala";
export const PERIOD_PRESETS = ["any", "today", "7d", "30d", "this_month", "last_month", "90d", "this_year"] as const;
export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

/** Offset of `tz` from UTC in minutes at instant `d` (Africa/Douala: +60). */
export function tzOffsetMinutes(d: Date, tz = DEFAULT_TZ): number {
  try {
    const parts = new Intl.DateTimeFormat("en-US", { timeZone: tz, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit" }).formatToParts(d);
    const g = (t: string) => Number(parts.find((p) => p.type === t)?.value);
    const asUtc = Date.UTC(g("year"), g("month") - 1, g("day"), g("hour") % 24, g("minute"), g("second"));
    return Math.round((asUtc - Math.floor(d.getTime() / 1000) * 1000) / 60000);
  } catch {
    return tz === DEFAULT_TZ ? 60 : 0;
  }
}

/** Local calendar parts of `d` in `tz`. */
function localParts(d: Date, tz: string) {
  const l = new Date(d.getTime() + tzOffsetMinutes(d, tz) * 60000);
  return { y: l.getUTCFullYear(), m: l.getUTCMonth(), day: l.getUTCDate() };
}
/** Instant of local midnight y-m-d in `tz` (month/day may overflow, Date.UTC normalises). */
function localMidnight(y: number, m: number, day: number, tz: string): Date {
  const guess = new Date(Date.UTC(y, m, day));
  return new Date(guess.getTime() - tzOffsetMinutes(guess, tz) * 60000);
}

/** `custom:2026-09-01..2026-09-27` value for a custom range (inclusive local dates). */
export const customPeriod = (from: string, to: string) => `custom:${from}..${to}`;
const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * [from, to) instants for a period value in `tz`. `any` / unknown -> null.
 * Month boundaries are local: "this_month" on 1 Oct 00:30 Douala is October.
 */
export function periodRange(value: string, now: Date = new Date(), tz = DEFAULT_TZ): { from: Date; to: Date } | null {
  const { y, m, day } = localParts(now, tz);
  const today = localMidnight(y, m, day, tz);
  const tomorrow = localMidnight(y, m, day + 1, tz);
  switch (value) {
    case "today":
      return { from: today, to: tomorrow };
    case "7d":
      return { from: localMidnight(y, m, day - 6, tz), to: tomorrow };
    case "30d":
      return { from: localMidnight(y, m, day - 29, tz), to: tomorrow };
    case "90d":
      return { from: localMidnight(y, m, day - 89, tz), to: tomorrow };
    case "this_month":
      return { from: localMidnight(y, m, 1, tz), to: localMidnight(y, m + 1, 1, tz) };
    case "last_month":
      return { from: localMidnight(y, m - 1, 1, tz), to: localMidnight(y, m, 1, tz) };
    case "this_year":
      return { from: localMidnight(y, 0, 1, tz), to: localMidnight(y + 1, 0, 1, tz) };
  }
  if (value.startsWith("custom:")) {
    let [a, b] = value.slice(7).split("..");
    // Typed backwards ("to" before "from"): swap the days so the range stays inclusive of both.
    if (a && b && ISO_DAY.test(a) && ISO_DAY.test(b) && a > b) [a, b] = [b, a];
    const pa = ISO_DAY.exec(a ?? "");
    const pb = ISO_DAY.exec(b ?? "");
    if (!pa && !pb) return null;
    const from = pa ? localMidnight(Number(pa[1]), Number(pa[2]) - 1, Number(pa[3]), tz) : new Date(0);
    const to = pb ? localMidnight(Number(pb[1]), Number(pb[2]) - 1, Number(pb[3]) + 1, tz) : new Date(8.64e15);
    return { from, to };
  }
  return null;
}

/** Does the ISO timestamp fall in the period? Rows without a date only match "any". */
export function inPeriod(iso: string | null | undefined, value: string, now?: Date, tz = DEFAULT_TZ): boolean {
  const r = periodRange(value, now, tz);
  if (!r) return true;
  if (!iso) return false;
  const t = new Date(iso).getTime();
  return Number.isFinite(t) && t >= r.from.getTime() && t < r.to.getTime();
}

/** Period matcher for applyFilters: `period: periodMatcher((r) => r.created_at)`. */
export const periodMatcher =
  <T>(date: (row: T) => string | null | undefined, now?: () => Date) =>
  (row: T, value: string) =>
    inPeriod(date(row), value, now?.());

// ---------------------------------------------------------------- sort + totals

export type Sorters<T> = Record<string, (a: T, b: T) => number>;
/** Rows in the order of the chosen sort key (unknown key keeps the server order). Stable, never mutates. */
export function sortRows<T>(rows: readonly T[], key: string | undefined, sorters: Sorters<T>): T[] {
  const cmp = key ? sorters[key] : undefined;
  if (!cmp) return [...rows];
  return rows.map((r, i) => [r, i] as const).sort((a, b) => cmp(a[0], b[0]) || a[1] - b[1]).map(([r]) => r);
}
/** Comparators for sorters. */
export const byText = <T>(get: (r: T) => unknown) => (a: T, b: T) => normalizeText(get(a)).localeCompare(normalizeText(get(b)));
export const byDate = <T>(get: (r: T) => string | null | undefined, dir: "asc" | "desc" = "desc") => (a: T, b: T) => {
  const ta = get(a) ? new Date(get(a) as string).getTime() : NaN;
  const tb = get(b) ? new Date(get(b) as string).getTime() : NaN;
  if (Number.isNaN(ta) && Number.isNaN(tb)) return 0;
  if (Number.isNaN(ta)) return 1;
  if (Number.isNaN(tb)) return -1;
  return dir === "desc" ? tb - ta : ta - tb;
};
export const byNumber = <T>(get: (r: T) => number | null | undefined, dir: "asc" | "desc" = "desc") => (a: T, b: T) =>
  dir === "desc" ? (get(b) ?? -Infinity) - (get(a) ?? -Infinity) : (get(a) ?? Infinity) - (get(b) ?? Infinity);

/** Sum + count of the (already filtered) rows; `where` narrows further (e.g. only SUCCEEDED). */
export function totals<T>(rows: readonly T[], amount: (r: T) => number | null | undefined, where?: (r: T) => boolean) {
  let total = 0;
  let count = 0;
  for (const r of rows) {
    if (where && !where(r)) continue;
    total += Number(amount(r) ?? 0) || 0;
    count += 1;
  }
  return { total, count };
}

/** Counts per bucket over rows (chip badges), computed from the rows passed in. */
export function countBy<T>(rows: readonly T[], bucket: (r: T) => string | null | undefined): Record<string, number> {
  const out: Record<string, number> = {};
  for (const r of rows) {
    const b = bucket(r);
    if (b) out[b] = (out[b] ?? 0) + 1;
  }
  return out;
}

/** One-call pipeline used by lists: filter + search, then sort by `values[sortKey][0]`. */
export function runList<T>(rows: readonly T[], o: { values: FilterValues; text?: string; matchers: Matchers<T>; haystack?: (r: T) => readonly unknown[]; sorters?: Sorters<T>; sortKey?: string }): T[] {
  const out = applyFilters(rows, o.values, o.matchers, o.text ?? "", o.haystack);
  return o.sorters ? sortRows(out, o.values[o.sortKey ?? "sort"]?.[0], o.sorters) : out;
}
