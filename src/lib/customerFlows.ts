/**
 * Pure helpers for the launch customer flows (complaints, statement,
 * instalments, refunds, discharge, payout, documents hub). Pure (relative .ts
 * imports only), so node:test loads it directly (tests/customer-flows.test.mjs).
 */
import { normalizeMomoPhone } from "./momoPhone.ts";

export type Tone = "success" | "warning" | "danger" | "info" | "neutral";

// ---------------------------------------------------------------- complaints
const COMPLAINT_CLOSED = ["CLOSED", "RESOLVED", "CANCELLED", "WITHDRAWN"];
export function complaintTone(status: string | null | undefined, open?: boolean): Tone {
  const s = String(status ?? "").toUpperCase();
  if (open === false || COMPLAINT_CLOSED.includes(s)) return "neutral";
  if (s === "WAITING_CUSTOMER") return "warning";
  if (s.startsWith("ESCALATED")) return "danger";
  return "info";
}
/** Minimum description the server accepts (MobileComplaintController: min 10). */
export const COMPLAINT_MIN_CHARS = 20;
export function complaintPayload(input: { description: string; policyId?: string | null; claimId?: string | null; contact?: string | null }) {
  const body: { description: string; policy_id?: string; claim_id?: string; contact?: string } = { description: input.description.trim() };
  // The server takes one subject: a claim wins over its policy.
  if (input.claimId) body.claim_id = input.claimId;
  else if (input.policyId) body.policy_id = input.policyId;
  const contact = (input.contact ?? "").trim();
  if (contact) body.contact = contact;
  return body;
}

// ----------------------------------------------------------------- statement
const pad = (n: number) => String(n).padStart(2, "0");
export const isoDay = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export type PeriodKey = "this_month" | "last_month" | "last_3_months" | "this_year" | "last_12_months";
export const PERIOD_KEYS: PeriodKey[] = ["this_month", "last_month", "last_3_months", "this_year", "last_12_months"];
/** Statement period for a preset, relative to `now` (local dates, inclusive). */
export function statementPeriod(key: PeriodKey, now: Date = new Date()): { from: string; to: string } {
  const y = now.getFullYear();
  const m = now.getMonth();
  switch (key) {
    case "last_month":
      return { from: isoDay(new Date(y, m - 1, 1)), to: isoDay(new Date(y, m, 0)) };
    case "last_3_months":
      return { from: isoDay(new Date(y, m - 2, 1)), to: isoDay(now) };
    case "this_year":
      return { from: isoDay(new Date(y, 0, 1)), to: isoDay(now) };
    case "last_12_months":
      return { from: isoDay(new Date(y, m - 11, 1)), to: isoDay(now) };
    default:
      return { from: isoDay(new Date(y, m, 1)), to: isoDay(now) };
  }
}
/** A statement balance is "owed by" the customer when positive (balance_meaning OWED_BY_SUBJECT). */
export function balanceSide(minor: number, meaning: string | null | undefined): "due" | "credit" | "zero" {
  if (!minor) return "zero";
  const owedBySubject = (meaning ?? "OWED_BY_SUBJECT") === "OWED_BY_SUBJECT";
  return (minor > 0) === owedBySubject ? "due" : "credit";
}

// --------------------------------------------------------------- instalments
export type InstalmentLike = {
  id: string;
  number: number;
  due_date: string;
  status: string;
  outstanding_minor: number;
  payable?: boolean;
  overdue?: boolean;
  payment_in_progress?: boolean;
};
const DAY = 86_400_000;
export const INSTALMENT_DUE_SOON_DAYS = 7;
export function instalmentTone(i: Pick<InstalmentLike, "status" | "overdue" | "payment_in_progress">): Tone {
  const s = String(i.status).toUpperCase();
  if (s === "PAID" || s === "WAIVED") return "success";
  if (s === "LAPSED" || s === "DEFAULTED") return "danger";
  if (i.payment_in_progress) return "info";
  if (i.overdue || s === "OVERDUE" || s === "GRACE") return "warning";
  return "neutral";
}
/** Days from `now` to the due date (negative when late); null for an unreadable date. */
export function daysToDue(dueDate: string, now: Date = new Date()): number | null {
  const at = Date.parse(dueDate.length === 10 ? `${dueDate}T00:00:00` : dueDate);
  if (Number.isNaN(at)) return null;
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  return Math.round((at - today) / DAY);
}
/**
 * Home "Needs your attention" instalments: overdue ones, then those due within
 * a week, soonest first. Paid / in-flight / unpayable lines never show.
 */
export function instalmentAttention<T extends InstalmentLike>(rows: T[], now: Date = new Date(), windowDays = INSTALMENT_DUE_SOON_DAYS) {
  const out: { instalment: T; days: number; overdue: boolean }[] = [];
  for (const r of rows) {
    if (!r.payable || r.payment_in_progress || r.outstanding_minor <= 0) continue;
    const days = daysToDue(r.due_date, now);
    if (days === null) continue;
    const overdue = !!r.overdue || days < 0;
    if (overdue || days <= windowDays) out.push({ instalment: r, days, overdue });
  }
  return out.sort((a, b) => Number(b.overdue) - Number(a.overdue) || a.days - b.days);
}

// ------------------------------------------------------------------- refunds
export function refundTone(status: string | null | undefined): Tone {
  const s = String(status ?? "").toUpperCase();
  if (["PAID", "COMPLETED", "RECONCILED"].includes(s)) return "success";
  if (["REJECTED", "CANCELLED"].includes(s)) return "danger";
  if (["APPROVED", "PROCESSING"].includes(s)) return "info";
  return "warning";
}
/** Refund notification deep link (/refunds/{id}) → the id, or null for any other path. */
export function refundIdFromPath(path: string | null | undefined): string | null {
  const m = /^\/refunds\/([0-9a-f-]{36})\/?$/i.exec(String(path ?? ""));
  return m?.[1] ?? null;
}

// ---------------------------------------------------------------- settlement
export type DischargeLike = { status?: string | null; signer_status?: string | null } | null | undefined;
/** Sign is offered only when the server lists sign_discharge (it checks the signer and the settlement state). */
export function canSignDischarge(s: { allowed_actions?: string[] | null; discharge?: DischargeLike } | null | undefined): boolean {
  return !!s && (s.allowed_actions ?? []).includes("sign_discharge") && s.discharge?.status === "PENDING";
}
export function canSetPayout(s: { allowed_actions?: string[] | null } | null | undefined): boolean {
  return !!s && (s.allowed_actions ?? []).includes("set_payout");
}
export const DISCHARGE_DECLINE_MIN = 5;
/** Claims whose settlement may be waiting on the customer (checked on Home, a few at most). */
export function settlementProbeClaims<C extends { id: string; status?: string | null }>(claims: C[], limit = 5): C[] {
  return claims.filter((c) => /APPROV|SETTLE|PAYMENT_PENDING/i.test(String(c.status ?? ""))).slice(0, limit);
}

/** Mobile money payout number: the checkout normalizer (src/lib/momoPhone.ts), mobile numbers (6XXXXXXXX) only. */
export function normalizeMsisdn(raw: string): string | null {
  const e164 = normalizeMomoPhone(raw);
  return /^\+2376\d{8}$/.test(e164) ? e164 : null;
}
export function payoutPayload(
  f: { method: "MOBILE_MONEY" | "BANK_TRANSFER"; operator?: string | null; msisdn?: string; bankName?: string; accountName?: string; accountNumber?: string },
):
  | { method: "MOBILE_MONEY"; operator: "MTN" | "ORANGE"; msisdn: string }
  | { method: "BANK_TRANSFER"; bank_name: string; account_name: string; account_number: string }
  | null {
  if (f.method === "MOBILE_MONEY") {
    const msisdn = normalizeMsisdn(f.msisdn ?? "");
    if (!msisdn || (f.operator !== "MTN" && f.operator !== "ORANGE")) return null;
    return { method: "MOBILE_MONEY", operator: f.operator, msisdn };
  }
  const bank_name = (f.bankName ?? "").trim();
  const account_name = (f.accountName ?? "").trim();
  const account_number = (f.accountNumber ?? "").replace(/\s+/g, "").toUpperCase();
  if (bank_name.length < 2 || account_name.length < 2 || !/^[A-Z0-9]{8,40}$/.test(account_number)) return null;
  return { method: "BANK_TRANSFER", bank_name, account_name, account_number };
}

// -------------------------------------------------------------- documents hub
export type HubDocument = { id: string; owner_type?: string | null; label?: string | null; status?: string | null; issued_at?: string | null; share_reference?: string | null };
export function documentHaystack(d: HubDocument): string[] {
  return [d.label ?? "", d.share_reference ?? "", d.owner_type ?? "", d.status ?? ""];
}

// ------------------------------------------------------------------ activity
/** "claim.settlement.payout_destination_set" → "claim_settlement_payout_destination_set" (i18n key suffix). */
export function activityKey(action: string): string {
  return action.replace(/[^a-z0-9]+/gi, "_").replace(/^_|_$/g, "").toLowerCase();
}
/** Readable fallback for an action with no translation: "policy.cancellation.requested" → "Policy cancellation requested". */
export function activityFallback(action: string): string {
  const words = action.replace(/[._-]+/g, " ").trim().toLowerCase();
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : action;
}
