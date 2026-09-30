/**
 * "Your contract" facts before the customer accepts (terms screen, application
 * hub, checkout): cover period, payment plan, what happens on non-payment, and
 * whether the terms can be accepted in the application's current state.
 *
 * Server facts only. Cover terms come from proposals.cover_terms (GET
 * proposals/{id} and the checklist; REQ-PRP-005 CoverTermsService) and the
 * product rule (checklist cover_term_rule). Null cover terms mean the server
 * defaults: start when the policy is issued (after payment), the rule's first
 * duration, single payment.
 *
 * Dependency-free apart from sibling libs: tests/contract-terms.test.mjs loads
 * it with Node's type stripping.
 */
import { isPayable, readyForTerms, type PaidState } from "./paymentRouting.ts";
import { proposalStatusInfo } from "./purchase.ts";

export type ScheduleRowLike = { sequence?: number | null; due?: string | null; amount_minor?: number | null; fee_minor?: number | null };
export type CoverTermsLike = {
  effective_rule?: string | null;
  start_date?: string | null;
  ends_before?: string | null;
  duration?: { unit?: string | null; value?: number | null } | null;
  instalment_plan?: string | null;
  schedule?: ScheduleRowLike[] | null;
  total_payable_minor?: number | null;
  non_payment_consequence?: string | null;
};
export type CoverRuleLike = { default_effective_rule?: string | null; durations?: { unit?: string | null; value?: number | null }[] | null; non_payment_consequence?: string | null };

const upper = (v: unknown) => String(v ?? "").trim().toUpperCase();
const isDate = (v: unknown): v is string => typeof v === "string" && Number.isFinite(Date.parse(v));
/** A date-only value read at midday so no timezone shows the previous day. */
export const calendarDate = (v: string) => (/^\d{4}-\d{2}-\d{2}$/.test(v) ? `${v}T12:00:00` : v);

export type CoverStart = { kind: "date"; date: string } | { kind: "event"; event: "PAYMENT" | "APPROVAL" | "MIDNIGHT" };

/**
 * When cover starts. An issued snapshot date wins; then a chosen date
 * (SPECIFIED_DATE / CUSTOM); otherwise the event the server resolves at
 * issuance (IMMEDIATE / PAYMENT_DATE = when payment is confirmed).
 */
export function coverStart(terms: CoverTermsLike | null | undefined, rule?: CoverRuleLike | null, snapshotStart?: string | null): CoverStart {
  if (isDate(snapshotStart)) return { kind: "date", date: snapshotStart };
  const effective = upper(terms?.effective_rule ?? rule?.default_effective_rule ?? "IMMEDIATE");
  if ((effective === "SPECIFIED_DATE" || effective === "CUSTOM") && isDate(terms?.start_date)) return { kind: "date", date: calendarDate(terms!.start_date!) };
  if (effective === "APPROVAL_DATE") return { kind: "event", event: "APPROVAL" };
  if (effective === "MIDNIGHT_RULE") return { kind: "event", event: "MIDNIGHT" };
  return { kind: "event", event: "PAYMENT" };
}

export type CoverEnd = { kind: "date"; date: string } | { kind: "duration"; unit: "DAY" | "MONTH"; value: number };

/** When cover ends: a known date, else the chosen (or the product's default) duration after the start. */
export function coverEnd(terms: CoverTermsLike | null | undefined, rule?: CoverRuleLike | null, snapshotEnd?: string | null): CoverEnd {
  if (isDate(snapshotEnd)) return { kind: "date", date: snapshotEnd };
  if (isDate(terms?.ends_before)) return { kind: "date", date: calendarDate(terms!.ends_before!) };
  for (const d of [terms?.duration, rule?.durations?.[0]]) {
    const unit = upper(d?.unit);
    const value = Number(d?.value);
    if ((unit === "DAY" || unit === "MONTH") && Number.isFinite(value) && value > 0) return { kind: "duration", unit, value };
  }
  // Server default (config proposals.cover_terms): 12 months.
  return { kind: "duration", unit: "MONTH", value: 12 };
}

export type ScheduleRow = { sequence: number; due: { kind: "bind" } | { kind: "months"; months: number } | { kind: "later" }; amountMinor: number; feeMinor: number };
export type PaymentPlan = { plan: string; rows: ScheduleRow[]; totalMinor: number | null };

/** Payment plan and instalment schedule (first payment at bind). Null cover terms = one single payment of the total. */
export function paymentPlan(terms: CoverTermsLike | null | undefined): PaymentPlan {
  const plan = upper(terms?.instalment_plan) || "SINGLE";
  const rows: ScheduleRow[] = (Array.isArray(terms?.schedule) ? terms!.schedule! : [])
    .filter((r) => typeof r?.amount_minor === "number" && Number.isFinite(r.amount_minor))
    .map((r, i) => {
      const due = upper(r.due);
      const m = /^\+(\d+)M$/.exec(due);
      return {
        sequence: typeof r.sequence === "number" ? r.sequence : i + 1,
        due: due === "AT_BIND" || (!due && i === 0) ? { kind: "bind" as const } : m ? { kind: "months" as const, months: Number(m[1]) } : { kind: "later" as const },
        amountMinor: r.amount_minor as number,
        feeMinor: typeof r.fee_minor === "number" ? r.fee_minor : 0,
      };
    })
    .sort((a, b) => a.sequence - b.sequence);
  const total = typeof terms?.total_payable_minor === "number" ? terms.total_payable_minor : rows.length ? rows.reduce((s, r) => s + r.amountMinor, 0) : null;
  return { plan, rows, totalMinor: total };
}

/** Instalments to show as a schedule (a single payment needs no table). */
export const hasInstalments = (p: PaymentPlan) => p.rows.length > 1;

const NON_PAYMENT = ["NO_COVER_UNTIL_PAID", "GRACE_THEN_SUSPEND", "CANCEL"] as const;
export type NonPayment = (typeof NON_PAYMENT)[number];

/** What happens when a payment is missed; null when the server has no verified rule (UNVERIFIED / absent). */
export function nonPaymentConsequence(terms?: CoverTermsLike | null, rule?: CoverRuleLike | null): NonPayment | null {
  const code = upper(terms?.non_payment_consequence ?? rule?.non_payment_consequence);
  return (NON_PAYMENT as readonly string[]).includes(code) ? (code as NonPayment) : null;
}

export type TermsBlock = "questions" | "documents" | "review" | "information" | "counteroffer" | "paid" | "closed";
export type TermsGate = { mode: "accept" } | { mode: "accepted" } | { mode: "blocked"; reason: TermsBlock };

/**
 * Whether the terms screen may ask for acceptance. Accepting is meaningful for
 * a payable application (then checkout) and for a DOCUMENTS_PENDING one whose
 * checklist blocks on nothing but the terms (accepting submits it). An
 * unknown checklist (older server) keeps the previous behaviour: accept.
 */
export function termsGate(input: { status: string | null | undefined; policyId?: string | null; blocking?: string[] | null; termsAccepted?: boolean | null; paid?: PaidState }): TermsGate {
  const status = upper(input.status);
  const stage = proposalStatusInfo(status).stage;
  // Money already taken (or with the operator) while still PAYMENT_PENDING: never back into checkout.
  if (input.policyId || stage === "paid" || input.paid) return { mode: "blocked", reason: "paid" };
  if (isPayable(status)) return input.termsAccepted ? { mode: "accepted" } : { mode: "accept" };
  if (status === "DOCUMENTS_PENDING") {
    if (!Array.isArray(input.blocking) || readyForTerms(status, input.blocking)) return { mode: "accept" };
    return { mode: "blocked", reason: "documents" };
  }
  if (stage === "disclosures") return { mode: "blocked", reason: "questions" };
  if (stage === "documents" || stage === "information") return { mode: "blocked", reason: stage === "documents" ? "documents" : "information" };
  if (stage === "counteroffer") return { mode: "blocked", reason: "counteroffer" };
  if (stage === "declined" || stage === "closed") return { mode: "blocked", reason: "closed" };
  return { mode: "blocked", reason: "review" };
}

/** Quote PDF (GET quotes/{id}/document) exists only once the quote is generated; null = unknown (let the viewer try). */
export function quoteDocumentReady(quote: { lifecycle_state?: string | null; status?: string | null } | null | undefined): boolean | null {
  if (!quote) return null;
  const state = upper(quote.lifecycle_state) || (upper(quote.status) === "ACCEPTED" ? "ACCEPTED" : "");
  if (!state) return null;
  return ["GENERATED", "SENT", "VIEWED", "ACCEPTED"].includes(state);
}
