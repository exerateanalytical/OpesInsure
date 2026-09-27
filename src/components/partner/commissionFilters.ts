/**
 * Shared commission ledger model + filters (COM-001..006), used by the agent
 * wallet and meant for the broker commissions screen too. Pure functions over
 * rows the server already scoped to the caller's own partner (COM-008): these
 * filters are a convenience, never an authorization boundary.
 */

/** COM-004 lifecycle. Server statuses outside this list are shown as-is. */
export const COMMISSION_STATES = [
  "ESTIMATED",
  "PENDING_ELIGIBILITY",
  "PENDING",
  "ACCRUED",
  "AVAILABLE",
  "STATEMENT",
  "APPROVED_FOR_PAYOUT",
  "PAID",
  "REVERSED",
  "ADJUSTED",
  "DISPUTED",
  "WITHHELD",
] as const;

export type CommissionRow = {
  id: string;
  status: string;
  amount_minor: number;
  paid_minor?: number | null;
  currency: string;
  policy_id?: string | null;
  policy_number?: string | null;
  customer_id?: string | null;
  customer_name?: string | null;
  carrier_id?: string | null;
  carrier_name?: string | null;
  line_code?: string | null;
  product_name?: string | null;
  /** COM-003: broker-admin producer dimension only; never populated for an independent agent. */
  producer_id?: string | null;
  producer_name?: string | null;
  premium_minor?: number | null;
  rate_bps?: number | null;
  basis?: string | null;
  reason?: string | null;
  statement_number?: string | null;
  sale_at?: string | null;
  issued_at?: string | null;
  accrued_at?: string | null;
  available_at?: string | null;
  paid_at?: string | null;
};

export type Lifecycle = "all" | "unpaid" | "paid" | (typeof COMMISSION_STATES)[number];
export type Period = "all" | "today" | "week" | "month" | "prev_month" | "quarter" | "year" | "custom";
export type DateBasis = "sale" | "issue" | "accrual" | "payment";

export type CommissionFilter = {
  lifecycle: Lifecycle;
  statuses: string[];
  carriers: string[];
  lines: string[];
  producers: string[];
  period: Period;
  basis: DateBasis;
  /** Custom period, inclusive ISO dates (YYYY-MM-DD). */
  from?: string;
  to?: string;
};

export const defaultCommissionFilter = (): CommissionFilter => ({
  lifecycle: "all",
  statuses: [],
  carriers: [],
  lines: [],
  producers: [],
  period: "all",
  basis: "accrual",
});

const PAID = new Set(["PAID"]);
const NOT_EARNING = new Set(["REVERSED", "CANCELLED", "VOID"]);

export const isPaid = (r: CommissionRow) => PAID.has(r.status);
export const isUnpaid = (r: CommissionRow) => !PAID.has(r.status) && !NOT_EARNING.has(r.status);

export const basisDate = (r: CommissionRow, basis: DateBasis): string | null | undefined =>
  basis === "sale"
    ? r.sale_at
    : basis === "issue"
      ? r.issued_at
      : basis === "payment"
        ? r.paid_at
        : (r.accrued_at ?? r.available_at);

/** [start, end) in ms for a preset, relative to `now`. */
export function periodRange(period: Period, now = new Date(), from?: string, to?: string): [number, number] | null {
  const y = now.getFullYear();
  const m = now.getMonth();
  const d = now.getDate();
  switch (period) {
    case "all":
      return null;
    case "today":
      return [new Date(y, m, d).getTime(), new Date(y, m, d + 1).getTime()];
    case "week": {
      const dow = (now.getDay() + 6) % 7; // Monday start
      return [new Date(y, m, d - dow).getTime(), new Date(y, m, d - dow + 7).getTime()];
    }
    case "month":
      return [new Date(y, m, 1).getTime(), new Date(y, m + 1, 1).getTime()];
    case "prev_month":
      return [new Date(y, m - 1, 1).getTime(), new Date(y, m, 1).getTime()];
    case "quarter": {
      const q = Math.floor(m / 3) * 3;
      return [new Date(y, q, 1).getTime(), new Date(y, q + 3, 1).getTime()];
    }
    case "year":
      return [new Date(y, 0, 1).getTime(), new Date(y + 1, 0, 1).getTime()];
    case "custom": {
      const a = from ? Date.parse(`${from}T00:00:00`) : NaN;
      const b = to ? Date.parse(`${to}T00:00:00`) : NaN;
      if (Number.isNaN(a) && Number.isNaN(b)) return null;
      return [Number.isNaN(a) ? -Infinity : a, Number.isNaN(b) ? Infinity : b + 86_400_000];
    }
  }
}

export function matchesLifecycle(r: CommissionRow, l: Lifecycle) {
  if (l === "all") return true;
  if (l === "paid") return isPaid(r);
  if (l === "unpaid") return isUnpaid(r);
  return r.status === l;
}

export function applyCommissionFilter(rows: CommissionRow[], f: CommissionFilter, now = new Date()) {
  const range = periodRange(f.period, now, f.from, f.to);
  return rows.filter((r) => {
    if (!matchesLifecycle(r, f.lifecycle)) return false;
    if (f.statuses.length && !f.statuses.includes(r.status)) return false;
    if (f.carriers.length && !f.carriers.includes(r.carrier_id ?? "")) return false;
    if (f.lines.length && !f.lines.includes(r.line_code ?? "")) return false;
    if (f.producers.length && !f.producers.includes(r.producer_id ?? "")) return false;
    if (range) {
      const iso = basisDate(r, f.basis);
      const ms = iso ? Date.parse(iso) : NaN;
      if (Number.isNaN(ms) || ms < range[0] || ms >= range[1]) return false;
    }
    return true;
  });
}

/** Filtered KPI aggregation (COM-001/002/004): every figure is a sum over the same rows the ledger shows. */
export function commissionTotals(rows: CommissionRow[]) {
  let total = 0;
  let unpaid = 0;
  let available = 0;
  let paid = 0;
  for (const r of rows) {
    if (NOT_EARNING.has(r.status)) continue;
    total += r.amount_minor;
    if (isPaid(r)) paid += r.amount_minor;
    else {
      unpaid += r.amount_minor - (r.paid_minor ?? 0);
      if (r.status === "AVAILABLE") available += r.amount_minor - (r.paid_minor ?? 0);
    }
  }
  return { total, unpaid, available, paid, count: rows.length };
}

/** Distinct option values present in the data (callers only offer filters the data can honour). */
export function distinct(rows: CommissionRow[], key: (r: CommissionRow) => [string | null | undefined, string | null | undefined]) {
  const m = new Map<string, string>();
  for (const r of rows) {
    const [v, label] = key(r);
    if (v) m.set(v, label || v);
  }
  return [...m.entries()].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label));
}
