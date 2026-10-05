import { api, type ClaimSettlement } from "./client";
import { environmentConfig } from "@/config/environment";
import { releasedSettlement } from "@/lib/settlement";

/**
 * Customer flows added for launch (2026-10-02): formal complaints, account
 * statement, account activity, instalments, refunds, settlement discharge
 * signing and payout details. Kept out of client.ts (purchase flow) and
 * customer.ts so parallel work on those files does not collide.
 */

// ---------------------------------------------------------------- complaints
/** GET/POST /mobile/complaints, GET /mobile/complaints/{id} (MobileComplaintController, REQ-CPL-001). */
export type Complaint = {
  id: string;
  case_id: string;
  complaint_number: string;
  status: string;
  open: boolean;
  channel: string;
  subject_type: "policy" | "claim" | null;
  subject_id: string | null;
  description: string;
  category: string | null;
  received_at: string | null;
  acknowledged_at: string | null;
  due_at: string | null;
  outcome: string | null;
  resolution_summary: string | null;
  communicated_at: string | null;
  escalation_level: number | string | null;
  escalated_at: string | null;
  closed_at: string | null;
  timeline?: { type: string; from_status: string | null; to_status: string | null; occurred_at: string }[];
  correspondence?: {
    reference_number: string | null;
    direction: string;
    channel: string | null;
    subject_line: string | null;
    summary: string | null;
    status: string | null;
    received_at: string | null;
    dispatched_at: string | null;
    created_at: string;
  }[];
};
export type ComplaintInput = { description: string; policy_id?: string; claim_id?: string; contact?: string };

export const ComplaintsApi = {
  list: () => api<Complaint[]>("/mobile/complaints"),
  show: (id: string) => api<Complaint>(`/mobile/complaints/${encodeURIComponent(id)}`),
  /** One Idempotency-Key per filing (the caller keeps it across retries of the same form). */
  create: (payload: ComplaintInput, idempotencyKey: string) =>
    api<Complaint>("/mobile/complaints", { method: "POST", body: JSON.stringify(payload), idempotencyKey }),
};

// ----------------------------------------------------------------- statement
/** GET /mobile/statements (AccountStatementController::mine, REQ-PAY-015). */
export type StatementLine = {
  occurred_at: string;
  line_type: string;
  description: string;
  reference_type: string | null;
  reference_id: string | null;
  amount_minor: number;
  balance_minor: number;
};
export type AccountStatement = {
  statement_number: string;
  subject: { type: string; id: string; name: string | null };
  balance_meaning: "OWED_BY_SUBJECT" | "OWED_TO_SUBJECT";
  period_start: string;
  period_end: string;
  currency: string;
  opening_balance_minor: number;
  lines: StatementLine[];
  totals_by_type: Record<string, number>;
  closing_balance_minor: number;
  generated_at: string;
};
export type StatementPeriod = { from: string; to: string; currency?: string };
const statementQuery = (p: StatementPeriod, pdf = false) =>
  `from=${encodeURIComponent(p.from)}&to=${encodeURIComponent(p.to)}&currency=${encodeURIComponent(p.currency ?? "XAF")}${pdf ? "&format=pdf" : ""}`;

export const StatementsApi = {
  get: (p: StatementPeriod) => api<AccountStatement>(`/mobile/statements?${statementQuery(p)}`),
  /** Absolute API URL of the PDF, for the in-app viewer (fetched with the bearer token). */
  pdfUrl: (p: StatementPeriod) => `${environmentConfig.apiBaseUrl}/mobile/statements?${statementQuery(p, true)}`,
};

// ------------------------------------------------------------------ activity
/** GET /mobile/account/activity (MobileActivityController): the caller's own audit trail. */
export type ActivityEntry = {
  sequence: number;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  source: string | null;
  occurred_at: string;
};
export const ActivityApi = {
  list: (before?: number, limit = 50) =>
    api<ActivityEntry[]>(`/mobile/account/activity?limit=${limit}${before ? `&before=${before}` : ""}`),
};

// --------------------------------------------------------------- instalments
/** GET /mobile/policies/{id}/instalments (MobileCustomerMoneyController, REQ-PAY-006). */
export type Instalment = {
  id: string;
  number: number;
  due_date: string;
  amount_minor: number;
  paid_minor: number;
  outstanding_minor: number;
  currency: string;
  status: "DUE" | "OVERDUE" | "GRACE" | "DEFAULTED" | "LAPSED" | "PAID" | "WAIVED" | string;
  paid_at: string | null;
  grace_ends_on: string | null;
  overdue: boolean;
  payable: boolean;
  payment_id: string | null;
  payment_status: string | null;
  payment_in_progress: boolean;
};
export type InstalmentSchedule = {
  data: Instalment[];
  meta: { policy_id: string; policy_number: string | null; currency: string; outstanding_minor: number };
};
export type InstalmentPayment = {
  id: string;
  status: string;
  provider: string;
  payer_phone_e164: string;
  amount_minor: number;
  currency: string;
  proposal_id: string | null;
  provider_reference: string | null;
  created_at: string | null;
};
export const InstalmentsApi = {
  schedule: (policyId: string) =>
    api<InstalmentSchedule>(`/mobile/policies/${encodeURIComponent(policyId)}/instalments`, { envelope: true }),
  /** Same key for every retry of one attempt: the server returns the same payment instead of charging twice. */
  pay: (policyId: string, instalmentId: string, payload: { provider: string; payer_phone_e164: string }, idempotencyKey: string) =>
    api<InstalmentPayment>(`/mobile/policies/${encodeURIComponent(policyId)}/instalments/${encodeURIComponent(instalmentId)}/pay`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotencyKey,
    }),
};

// ------------------------------------------------------------------- refunds
/** GET /mobile/payments/{id}/refunds, GET /mobile/refunds/{id} (REQ-PAY-009). */
export type PaymentRefund = {
  id: string;
  payment_id: string;
  refund_number: string;
  status: string;
  amount_minor: number;
  currency: string;
  reason_code: string | null;
  payout_method: string | null;
  requested_at: string | null;
  approved_at: string | null;
  paid_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
};
export const RefundsApi = {
  forPayment: (paymentId: string) => api<PaymentRefund[]>(`/mobile/payments/${encodeURIComponent(paymentId)}/refunds`),
  show: (id: string) => api<PaymentRefund>(`/mobile/refunds/${encodeURIComponent(id)}`),
};

// ---------------------------------------------------------------- settlement
export type SettlementDischarge = {
  signature_request_id: string;
  document_id: string | null;
  status: "PENDING" | "COMPLETED" | "DECLINED" | "CANCELLED" | string;
  consent_text: string;
  signer_status: string | null;
  signed_at: string | null;
  declined_at: string | null;
  expires_at: string | null;
};
export type SettlementPayout = {
  method: "MOBILE_MONEY" | "BANK_TRANSFER";
  operator: "MTN" | "ORANGE" | null;
  msisdn_masked: string | null;
  bank_name: string | null;
  account_name: string | null;
  account_number_masked: string | null;
  updated_at: string | null;
};
export type PayoutInput =
  | { method: "MOBILE_MONEY"; operator: "MTN" | "ORANGE"; msisdn: string }
  | { method: "BANK_TRANSFER"; bank_name: string; account_name: string; account_number: string };
/** MobileClaimSettlementView::present with the discharge / payout / advice fields. */
export type SettlementDetail = ClaimSettlement & {
  discharge?: SettlementDischarge | null;
  payout?: SettlementPayout | null;
  payment_advice_document_id?: string | null;
  allowed_actions?: string[];
};
export const SettlementFlowsApi = {
  get: async (claimId: string) =>
    releasedSettlement(await api<SettlementDetail | { id: null; status: string }>(`/mobile/claims/${encodeURIComponent(claimId)}/settlement`)) as SettlementDetail | null,
  signDischarge: (claimId: string) =>
    api<SettlementDetail>(`/mobile/claims/${encodeURIComponent(claimId)}/settlement/discharge/sign`, {
      method: "POST",
      body: JSON.stringify({ consent_accepted: true }),
      idempotent: true,
      stepUpPurpose: "CLAIM_SETTLEMENT_DECISION",
    }),
  declineDischarge: (claimId: string, reason: string) =>
    api<SettlementDetail>(`/mobile/claims/${encodeURIComponent(claimId)}/settlement/discharge/decline`, {
      method: "POST",
      body: JSON.stringify({ reason }),
      idempotent: true,
    }),
  setPayout: (claimId: string, payload: PayoutInput) =>
    api<SettlementDetail>(`/mobile/claims/${encodeURIComponent(claimId)}/settlement/payout`, {
      method: "PUT",
      body: JSON.stringify(payload),
      idempotent: true,
      // On the server rollout hold today; a grant is attached when the user just stepped up.
      stepUpIfGranted: "PAYOUT_DESTINATION_CHANGE",
    }),
};
