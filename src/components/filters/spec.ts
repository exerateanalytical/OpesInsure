/**
 * One-call list specification for portal lists (agent / carrier queues):
 * builds the standard sections (status, grouping dimensions, period, sort),
 * their matchers and sorters from field getters, so every list gets the same
 * filter sheet without per-screen filtering code.
 */
import type { FilterOption, FilterSection } from "@/components/customer/FiltersSheet";
import type { CopyKey } from "@/i18n/strings";
import { byDate, byNumber, byText, optionsFrom, periodMatcher, type Matchers, type Sorters } from "./core";
import { periodSection, sortSection } from "./index";

/** "EVIDENCE_PENDING" -> "Evidence pending" when no translated label is given. */
export const humanizeCode = (v: string) => {
  if (!/^[A-Za-z0-9]+(_[A-Za-z0-9]+)*$/.test(v) || (!v.includes("_") && v !== v.toUpperCase())) return v;
  const s = v.replace(/_/g, " ").toLowerCase().trim();
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : v;
};

type Tr = (k: CopyKey, p?: Record<string, string | number>) => string;

export type ListDim<T> = { key: string; title: string; get: (r: T) => { value: string | null | undefined; label?: string | null } | null };

export function listSpec<T>(
  rows: readonly T[],
  t: Tr,
  o: {
    /** Status value + label. */
    status?: (r: T) => string | null | undefined;
    statusLabel?: (v: string) => string;
    /** Extra multi-select dimensions (insurer, product line, priority, partner...). */
    dims?: ListDim<T>[];
    /** Row date used by the period filter and the newest / oldest sort. */
    date?: (r: T) => string | null | undefined;
    dateTitle?: string;
    /** Amount (minor units) for the amount sorts. */
    amount?: (r: T) => number | null | undefined;
    /** Name for the A-Z sort. */
    name?: (r: T) => unknown;
  },
): { sections: FilterSection[]; matchers: Matchers<T>; sorters: Sorters<T> } {
  const sections: FilterSection[] = [];
  const matchers: Matchers<T> = {};
  const sorters: Sorters<T> = {};
  const sortOptions: FilterOption[] = [];
  if (o.status) {
    const get = o.status;
    sections.push({ key: "status", title: t("filterStatus"), options: optionsFrom(rows, (r) => { const v = get(r); return v ? { value: v, label: o.statusLabel ? o.statusLabel(v) : humanizeCode(v) } : null; }) });
    matchers.status = (r, v) => get(r) === v;
  }
  for (const d of o.dims ?? []) {
    sections.push({ key: d.key, title: d.title, options: optionsFrom(rows, (r) => { const x = d.get(r); return x?.value ? { value: x.value, label: x.label ?? humanizeCode(x.value) } : null; }) });
    matchers[d.key] = (r, v) => d.get(r)?.value === v;
  }
  if (o.date) {
    const get = o.date;
    sections.push(periodSection(t, "period", o.dateTitle));
    matchers.period = periodMatcher(get);
    sortOptions.push({ value: "recent", label: t("fltSortRecent") }, { value: "oldest", label: t("fltSortOldest") });
    sorters.recent = byDate(get);
    sorters.oldest = byDate(get, "asc");
  }
  if (o.amount) {
    sortOptions.push({ value: "amount_desc", label: t("fltSortAmountHigh") }, { value: "amount_asc", label: t("fltSortAmountLow") });
    sorters.amount_desc = byNumber(o.amount);
    sorters.amount_asc = byNumber(o.amount, "asc");
  }
  if (o.name) {
    sortOptions.push({ value: "name", label: t("fltSortName") });
    sorters.name = byText(o.name);
  }
  // Server order stays the default when there is no date sort.
  if (sortOptions.length && !o.date) sortOptions.unshift({ value: "default", label: t("fltSortDefault") });
  if (sortOptions.length > 1) sections.push(sortSection(t, sortOptions));
  return { sections, matchers, sorters };
}
