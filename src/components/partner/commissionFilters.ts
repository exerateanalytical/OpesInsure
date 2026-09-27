/**
 * Shared commission ledger model + filters (COM-001..007), used by the agent
 * wallet and the broker earnings screen. Pure functions (no React) over rows
 * the server already scoped to the caller's own partner (COM-008): filters are
 * a convenience, never an authorization boundary. Built on the shared list
 * core (src/components/filters/core.ts) so search, periods (Africa/Douala
 * days), sort and saved filters behave like every other list.
 *
 * Money rules (every KPI is a sum over exactly the rows its drill-down shows):
 * - net      = amount - clawed back (0 for reversed / cancelled / void rows)
 * - paid     = paid_minor (a PAID row with no paid_minor counts its net)
 * - owed     = max(0, net - paid)
 * - earned   = net of every non-estimated, non-reversed row = owed + paid
 * Nothing here is authoritative: amounts, rates and states come from the server.
 */
import {
  applyFilters,
  byDate,
  byNumber,
  byText,
  inPeriod,
  periodRange,
  sortRows,
  type FilterValues,
  type Matchers,
  type Sorters,
} from "../filters/core.ts";

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
  clawed_back_minor?: number | null;
  vested_minor?: number | null;
  currency: string;
  policy_id?: string | null;
  policy_number?: string | null;
  /** Status of the linked policy (joined from the book), when known. */
  policy_status?: string | null;
  /** Sale links, when the server sends them (commission per sale). */
  proposal_id?: string | null;
  quote_id?: string | null;
  source_type?: string | null;
  source_id?: string | null;
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
  rule_version?: string | null;
  basis?: string | null;
  reason?: string | null;
  statement_number?: string | null;
  sale_at?: string | null;
  issued_at?: string | null;
  accrued_at?: string | null;
  available_at?: string | null;
  payable_at?: string | null;
  paid_at?: string | null;
};

/** Where a commission is in its life, in the words brokers use. */
export type Stage = "estimated" | "accrued" | "payable" | "paid" | "on_hold" | "reversed";
export type Lifecycle = "all" | "unpaid" | "payable" | "paid";
export const LIFECYCLES: Lifecycle[] = ["all", "unpaid", "payable", "paid"];
/** accrual = earned_at, issue = policy issued, sale = sale date, available = vesting date, payment = paid date. */
export type DateBasis = "accrual" | "issue" | "sale" | "available" | "payment";
export const BASES: DateBasis[] = ["accrual", "issue", "sale", "available", "payment"];
export const COMMISSION_SORTS = ["date_desc", "date_asc", "amount_desc", "amount_asc", "customer"] as const;

const NOT_EARNING = new Set(["REVERSED", "CANCELLED", "VOID"]);
const PAYABLE = new Set(["AVAILABLE", "STATEMENT", "APPROVED_FOR_PAYOUT", "PAYABLE", "VESTED"]);
const ON_HOLD = new Set(["DISPUTED", "WITHHELD"]);

export const netAmount = (r: CommissionRow) => (NOT_EARNING.has(r.status) ? 0 : Math.max(0, r.amount_minor - (r.clawed_back_minor ?? 0)));
export const paidAmount = (r: CommissionRow) => {
  const p = Math.max(0, r.paid_minor ?? 0);
  return r.status === "PAID" && p === 0 ? netAmount(r) : p;
};
export const owedAmount = (r: CommissionRow) => (NOT_EARNING.has(r.status) || r.status === "ESTIMATED" ? 0 : Math.max(0, netAmount(r) - paidAmount(r)));

export function stageOf(r: CommissionRow): Stage {
  if (NOT_EARNING.has(r.status)) return "reversed";
  if (r.status === "ESTIMATED") return "estimated";
  if (r.status === "PAID" || (paidAmount(r) > 0 && owedAmount(r) === 0)) return "paid";
  if (ON_HOLD.has(r.status)) return "on_hold";
  if (PAYABLE.has(r.status)) return "payable";
  return "accrued";
}

export const isPaid = (r: CommissionRow) => paidAmount(r) > 0 && !NOT_EARNING.has(r.status);
export const isUnpaid = (r: CommissionRow) => owedAmount(r) > 0;

export function matchesLifecycle(r: CommissionRow, l: string) {
  if (l === "unpaid") return isUnpaid(r);
  if (l === "payable") return stageOf(r) === "payable" && owedAmount(r) > 0;
  if (l === "paid") return isPaid(r);
  return true;
}

export const basisDate = (r: CommissionRow, basis: DateBasis): string | null | undefined =>
  basis === "sale"
    ? r.sale_at
    : basis === "issue"
      ? r.issued_at
      : basis === "payment"
        ? r.paid_at
        : basis === "available"
          ? (r.available_at ?? r.payable_at)
          : r.accrued_at;

/** Date bases the data can honour (at least one row has that date), in preference order. */
export const availableBases = (rows: CommissionRow[]): DateBasis[] => {
  const b = BASES.filter((k) => rows.some((r) => !!basisDate(r, k)));
  return b.length ? b : ["accrual"];
};

const pick = (values: FilterValues, key: string) => values[key]?.[0];
export const basisOf = (values: FilterValues, rows: CommissionRow[]): DateBasis => {
  const v = pick(values, "basis") as DateBasis | undefined;
  const ok = availableBases(rows);
  return v && ok.includes(v) ? v : ok[0]!;
};

export const commissionHaystack = (r: CommissionRow) => [r.policy_number, r.customer_name, r.carrier_name, r.product_name, r.line_code, r.producer_name, r.statement_number, r.reason];

export function commissionMatchers(basis: DateBasis, now?: Date): Matchers<CommissionRow> {
  return {
    lifecycle: matchesLifecycle,
    statuses: (r, v) => r.status === v,
    carriers: (r, v) => (r.carrier_id ?? "") === v,
    lines: (r, v) => (r.line_code ?? "") === v,
    producers: (r, v) => (r.producer_id ?? "") === v,
    period: (r, v) => inPeriod(basisDate(r, basis), v, now),
  };
}

export function commissionSorters(basis: DateBasis): Sorters<CommissionRow> {
  return {
    date_desc: byDate((r) => basisDate(r, basis)),
    date_asc: byDate((r) => basisDate(r, basis), "asc"),
    amount_desc: byNumber((r) => r.amount_minor),
    amount_asc: byNumber((r) => r.amount_minor, "asc"),
    customer: byText((r) => r.customer_name),
  };
}

/**
 * "custom:TO..FROM" typed backwards becomes "custom:FROM..TO" (core swaps the
 * exclusive instants instead, which yields an empty window).
 */
export function normalizePeriod(v: string | undefined): string | undefined {
  if (!v?.startsWith("custom:")) return v;
  const [a = "", b = ""] = v.slice(7).split("..");
  const day = /^\d{4}-\d{2}-\d{2}$/;
  return day.test(a) && day.test(b) && a > b ? `custom:${b}..${a}` : v;
}

/** Rows shown for a selection: filters + search, then the chosen sort (default newest by basis). */
export function runCommissions(rows: CommissionRow[], values: FilterValues, text = "", now?: Date): CommissionRow[] {
  const basis = basisOf(values, rows);
  const period = normalizePeriod(pick(values, "period"));
  const v = period ? { ...values, period: [period] } : values;
  const out = applyFilters(rows, v, commissionMatchers(basis, now), text, commissionHaystack);
  return sortRows(out, pick(values, "sort") ?? "date_desc", commissionSorters(basis));
}

/**
 * Rows the KPI cards sum: the same selection without the lifecycle choice, so
 * tapping "Paid" drills into paid rows without zeroing the other cards.
 */
export const kpiRows = (rows: CommissionRow[], values: FilterValues, text = "", now?: Date) => runCommissions(rows, { ...values, lifecycle: [] }, text, now);

/** Filtered KPI aggregation (COM-001/002/004). earned === unpaid + paid for the same rows. */
export function commissionTotals(rows: CommissionRow[]) {
  let total = 0;
  let unpaid = 0;
  let available = 0;
  let paid = 0;
  let estimated = 0;
  for (const r of rows) {
    const st = stageOf(r);
    if (st === "reversed") continue;
    if (st === "estimated") {
      estimated += netAmount(r);
      continue;
    }
    total += owedAmount(r) + paidAmount(r);
    unpaid += owedAmount(r);
    paid += paidAmount(r);
    if (st === "payable") available += owedAmount(r);
  }
  return { total, unpaid, available, paid, estimated, count: rows.length };
}

/**
 * Same-length window right before the chosen period (period comparison). Null
 * for "any" or an open-ended custom range.
 */
export function previousWindow(period: string | undefined, now?: Date): { from: number; to: number } | null {
  if (!period || period === "any") return null;
  const r = periodRange(period, now);
  if (!r || r.from.getTime() <= 0 || r.to.getTime() > 8e15) return null;
  if (period === "this_month" || period === "last_month") {
    const prev = periodRange("last_month", period === "this_month" ? now : new Date(r.from.getTime() + 1));
    return prev ? { from: prev.from.getTime(), to: prev.to.getTime() } : null;
  }
  const len = r.to.getTime() - r.from.getTime();
  return { from: r.from.getTime() - len, to: r.from.getTime() };
}

/** Earned in the chosen period vs. the window before it, same filters otherwise. */
export function periodComparison(rows: CommissionRow[], values: FilterValues, text = "", now?: Date) {
  const period = normalizePeriod(pick(values, "period"));
  const win = previousWindow(period, now);
  if (!win) return null;
  const basis = basisOf(values, rows);
  const current = commissionTotals(kpiRows(rows, values, text, now)).total;
  const others = kpiRows(rows, { ...values, period: [] }, text, now).filter((r) => {
    const t = Date.parse(basisDate(r, basis) ?? "");
    return Number.isFinite(t) && t >= win.from && t < win.to;
  });
  const previous = commissionTotals(others).total;
  const change = previous > 0 ? Math.round(((current - previous) / previous) * 1000) / 10 : null;
  return { current, previous, change };
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

/** "custom:YYYY-MM-DD..YYYY-MM-DD" for the calendar quarter containing `now` (Douala dates). */
export function quarterValue(now = new Date()) {
  const r = periodRange("this_month", now)!;
  const local = new Date(r.from.getTime() + 12 * 3600_000); // mid-day of the 1st, safely inside the month
  const y = local.getUTCFullYear();
  const q = Math.floor(local.getUTCMonth() / 3) * 3;
  const last = new Date(Date.UTC(y, q + 3, 0)).getUTCDate();
  const mm = (m: number) => String(m + 1).padStart(2, "0");
  return `custom:${y}-${mm(q)}-01..${y}-${mm(q + 2)}-${String(last).padStart(2, "0")}`;
}

// ---------------------------------------------------------------- per sale

export type SaleCommission = {
  /** Server-calculated rows for this sale (one policy can carry several). */
  count: number;
  stage: Stage;
  /** True when every row is only a server estimate (status ESTIMATED). */
  estimated: boolean;
  amount_minor: number;
  paid_minor: number;
  owed_minor: number;
  currency: string;
  /** Contractual rate from the server, when sent. */
  rate_bps: number | null;
  /** amount ÷ premium when both come from the server (display only). */
  effective_rate_bps: number | null;
  paid_at: string | null;
  /** When it becomes / became payable (vesting date). */
  payable_from: string | null;
  ids: string[];
};

/**
 * Commission for one sale, joined on the server's accrual rows by policy id
 * (or proposal / quote / source id when the server sends them). Returns null
 * when the server has not calculated anything yet: the UI then says "Pending
 * calculation" and never guesses an amount.
 */
export function saleCommission(
  rows: CommissionRow[],
  sale: { policyId?: string | null; proposalId?: string | null; quoteId?: string | null; premiumMinor?: number | null },
): SaleCommission | null {
  const mine = rows.filter(
    (r) =>
      (!!sale.policyId && (r.policy_id === sale.policyId || r.source_id === sale.policyId)) ||
      (!!sale.proposalId && (r.proposal_id === sale.proposalId || r.source_id === sale.proposalId)) ||
      (!!sale.quoteId && (r.quote_id === sale.quoteId || r.source_id === sale.quoteId)),
  );
  if (!mine.length) return null;
  const live = mine.filter((r) => stageOf(r) !== "reversed");
  const stages = new Set(live.map(stageOf));
  const estimated = live.length > 0 && live.every((r) => stageOf(r) === "estimated");
  const amount = estimated ? live.reduce((s, r) => s + netAmount(r), 0) : live.reduce((s, r) => s + owedAmount(r) + paidAmount(r), 0);
  const paid = live.reduce((s, r) => s + paidAmount(r), 0);
  const owed = live.reduce((s, r) => s + owedAmount(r), 0);
  const stage: Stage = !live.length
    ? "reversed"
    : estimated
      ? "estimated"
      : owed === 0 && paid > 0
        ? "paid"
        : stages.has("on_hold")
          ? "on_hold"
          : stages.has("payable")
            ? "payable"
            : "accrued";
  const rates = [...new Set(live.map((r) => r.rate_bps).filter((v): v is number => v != null))];
  const premium = sale.premiumMinor ?? live.find((r) => r.premium_minor != null)?.premium_minor ?? null;
  const latest = (xs: (string | null | undefined)[]) => xs.filter((x): x is string => !!x).sort().at(-1) ?? null;
  const earliest = (xs: (string | null | undefined)[]) => xs.filter((x): x is string => !!x).sort()[0] ?? null;
  return {
    count: mine.length,
    stage,
    estimated,
    amount_minor: amount,
    paid_minor: paid,
    owed_minor: owed,
    currency: mine[0]!.currency,
    rate_bps: rates.length === 1 ? rates[0]! : null,
    effective_rate_bps: premium && premium > 0 && amount > 0 ? Math.round((amount / premium) * 10000) : null,
    paid_at: stage === "paid" ? latest(live.map((r) => r.paid_at)) : null,
    payable_from: earliest(live.map((r) => r.payable_at ?? r.available_at)),
    ids: mine.map((r) => r.id),
  };
}
