/**
 * Commercial Agent Earnings (spec v2 screens 05-08): locked status vocabulary,
 * the Earnings filter model and display summaries over rows the server already
 * scoped to the signed-in agent (COM-008). Pure functions (no React).
 *
 * Nothing here is authoritative money: every amount is a server value; the
 * tiles are display sums over exactly the rows the list shows (same rules as
 * commissionFilters.commissionTotals), never a balance the phone decides.
 */
import { inPeriod, type FilterValues } from "../filters/core.ts";
import {
  commissionTotals,
  netAmount,
  owedAmount,
  paidAmount,
  runCommissions,
  stageOf,
  type CommissionRow,
} from "./commissionFilters.ts";

// ------------------------------------------------------------ vocabulary

/** Locked commission vocabulary (AGENT_UI_SPEC_V2 §10). */
export const COMMISSION_VOCAB = ["accrued", "pending", "available", "paid", "reversed", "disputed"] as const;
export type CommissionVocab = (typeof COMMISSION_VOCAB)[number];

/** Server commission status -> locked label key. Unknown statuses read "Accrued" (earned, not yet payable). */
export function commissionVocab(r: Pick<CommissionRow, "status"> & Partial<CommissionRow>): CommissionVocab {
  const s = (r.status ?? "").toUpperCase();
  if (s === "REVERSED" || s === "CANCELLED" || s === "VOID") return "reversed";
  if (s === "DISPUTED" || s === "WITHHELD") return "disputed";
  if (s === "PAID") return "paid";
  if (s === "ESTIMATED" || s === "PENDING" || s === "PENDING_ELIGIBILITY") return "pending";
  if (r.amount_minor !== undefined && stageOf(r as CommissionRow) === "paid") return "paid";
  if (["AVAILABLE", "STATEMENT", "APPROVED_FOR_PAYOUT", "PAYABLE", "VESTED"].includes(s)) return "available";
  return "accrued";
}

export type Tone = "success" | "warning" | "danger" | "info" | "neutral";
export const commissionTone = (v: CommissionVocab): Tone =>
  v === "paid" ? "success" : v === "reversed" ? "danger" : v === "disputed" ? "warning" : v === "available" ? "info" : v === "pending" ? "warning" : "neutral";

/** Locked withdrawal vocabulary. */
export const WITHDRAWAL_VOCAB = ["requested", "under_review", "processing", "paid", "failed", "rejected", "reversed", "cancelled"] as const;
export type WithdrawalVocab = (typeof WITHDRAWAL_VOCAB)[number];
export const WITHDRAWAL_FLOW: WithdrawalVocab[] = ["requested", "under_review", "processing", "paid"];
const W_TERMINAL = new Set<WithdrawalVocab>(["failed", "rejected", "reversed", "cancelled"]);

export function withdrawalVocab(status: string | null | undefined): WithdrawalVocab {
  const s = (status ?? "").toUpperCase();
  if (s === "PAID" || s === "COMPLETED" || s === "SUCCEEDED" || s === "SETTLED") return "paid";
  if (s === "FAILED" || s === "ERROR") return "failed";
  if (s === "REJECTED" || s === "DECLINED") return "rejected";
  if (s === "REVERSED" || s === "REFUNDED") return "reversed";
  if (s === "CANCELLED" || s === "CANCELED" || s === "VOID") return "cancelled";
  if (s === "PROCESSING" || s === "SUBMITTED" || s === "IN_PROGRESS" || s === "DISBURSING") return "processing";
  if (s === "APPROVED" || s === "UNDER_REVIEW" || s === "IN_REVIEW" || s === "REVIEW") return "under_review";
  return "requested";
}
export const isWithdrawalTerminalFailure = (v: WithdrawalVocab) => W_TERMINAL.has(v);
export const withdrawalTone = (v: WithdrawalVocab): Tone => (v === "paid" ? "success" : W_TERMINAL.has(v) ? "danger" : v === "requested" ? "neutral" : "warning");

/** Timeline steps reached for a withdrawal (index into WITHDRAWAL_FLOW; -1 for a failure outcome). */
export const withdrawalReached = (v: WithdrawalVocab) => WITHDRAWAL_FLOW.indexOf(v);

// ------------------------------------------------------------ masking

/**
 * "+237 6•• ••• 432" from a full or server-masked Cameroon number. Keeps the
 * operator digit and at most the last 3 digits; a server-masked value
 * ("+237600••••") never reveals more than it already did.
 */
export function maskPhone(raw: string | null | undefined): string {
  const v = (raw ?? "").replace(/\s+/g, "");
  if (!v) return "—";
  const masked = v.includes("•") || v.includes("*");
  const digits = v.replace(/[^\d]/g, "");
  const national = digits.startsWith("237") ? digits.slice(3) : digits;
  const prefix = v.startsWith("+") || digits.startsWith("237") ? "+237 " : "";
  if (!national) return "•••";
  const first = national[0];
  const tail = masked ? "•••" : national.length >= 7 ? national.slice(-3) : "•••";
  return `${prefix}${first}•• ••• ${tail}`;
}

export const providerLabel = (p: string | null | undefined) =>
  p === "orange_money" ? "Orange Money" : p === "mtn_momo" ? "MTN MoMo" : p ? p.replaceAll("_", " ") : "—";

// ------------------------------------------------------------ filters

export type EarningsRow = CommissionRow & {
  policy_status?: string | null;
  branch_id?: string | null;
  branch_name?: string | null;
};

export type PaymentState = "unpaid" | "partial" | "paid";
export function paymentState(r: CommissionRow): PaymentState | null {
  if (commissionVocab(r) === "reversed") return null;
  const p = paidAmount(r);
  if (p <= 0) return "unpaid";
  return owedAmount(r) > 0 ? "partial" : "paid";
}

/** Filter keys of the Earnings sheet (values are the FilterValues arrays). */
export const EARNINGS_KEYS = ["period", "carriers", "products", "lines", "customers", "producers", "vocab", "payment", "policy_status", "branches"] as const;

const eq = (a: string | null | undefined, b: string) => (a ?? "") === b;

function matches(r: EarningsRow, values: FilterValues): boolean {
  const any = (key: string, test: (v: string) => boolean) => {
    const vs = values[key] ?? [];
    return vs.length === 0 || vs.some(test);
  };
  return (
    any("products", (v) => eq(r.product_name, v)) &&
    any("customers", (v) => eq(r.customer_id ?? r.customer_name, v)) &&
    any("vocab", (v) => commissionVocab(r) === v) &&
    any("payment", (v) => paymentState(r) === v) &&
    any("policy_status", (v) => eq(r.policy_status, v)) &&
    any("branches", (v) => eq(r.branch_id, v))
  );
}

/**
 * Rows for the Earnings list: the shared commission run (search, period,
 * insurer, line, producer, sort) plus the agent-only dimensions.
 */
export function runEarnings(rows: EarningsRow[], values: FilterValues, text = "", now?: Date): EarningsRow[] {
  const shared: FilterValues = {};
  for (const k of ["period", "carriers", "lines", "producers", "sort", "basis"]) if (values[k]?.length) shared[k] = values[k]!;
  return (runCommissions(rows, shared, text, now) as EarningsRow[]).filter((r) => matches(r, values));
}

/** Distinct options present in the data, label-sorted. */
export function optionsOf(rows: EarningsRow[], pick: (r: EarningsRow) => [string | null | undefined, string | null | undefined]) {
  const m = new Map<string, string>();
  for (const r of rows) {
    const [v, l] = pick(r);
    if (v) m.set(v, l || v);
  }
  return [...m.entries()].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label));
}

// ------------------------------------------------------------ summaries

export type Tile = "available" | "pending" | "paid_month" | "withdrawn";
/** Tile tap -> the filter it applies to the commission list (withdrawn filters the withdrawal list). */
export const TILE_FILTER: Record<Exclude<Tile, "withdrawn">, FilterValues> = {
  available: { vocab: ["available"] },
  pending: { vocab: ["pending", "accrued"] },
  paid_month: { payment: ["paid", "partial"] },
};

export type WithdrawalLike = { amount_minor: number; status: string; requested_at?: string | null };

/**
 * Display summary for the Earnings hero and tiles, over the rows in the chosen
 * period (`rows` is already period/filter-scoped by the caller).
 * - total earned = net earned (owed + paid, excl. estimates and reversals)
 * - available = owed on payable rows; pending = owed not yet payable + estimates
 * - paid this month = paid on rows whose payment date is this calendar month
 * - withdrawn = withdrawals the server marked paid
 */
export function earningsSummary(rows: EarningsRow[], withdrawals: WithdrawalLike[], now?: Date) {
  const t = commissionTotals(rows);
  const paidMonth = rows.reduce((s, r) => (inPeriod(r.paid_at, "this_month", now) ? s + paidAmount(r) : s), 0);
  const withdrawn = withdrawals.filter((w) => withdrawalVocab(w.status) === "paid").reduce((s, w) => s + Math.max(0, w.amount_minor), 0);
  return {
    total: t.total,
    available: t.available,
    pending: Math.max(0, t.unpaid - t.available) + t.estimated,
    paidMonth,
    withdrawn,
    currency: rows[0]?.currency ?? "XAF",
  };
}

/** Commission-calculation lines, server values only (null -> "—" / "Pending calculation" in the UI). */
export function calculationLines(r: CommissionRow) {
  const gross = r.status === "ESTIMATED" ? null : r.amount_minor;
  const adjustments = r.clawed_back_minor ? -Math.abs(r.clawed_back_minor) : null;
  return {
    premium: r.premium_minor ?? null,
    rate_bps: r.rate_bps ?? null,
    gross,
    adjustments,
    net: gross === null ? null : netAmount(r),
    estimated: r.status === "ESTIMATED",
  };
}
