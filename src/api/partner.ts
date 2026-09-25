/**
 * Wave 16 partner workspaces — agent leads/quotes/policies, broker
 * quotes/policies/claims/staff/commissions, insurer products/proposals/
 * policies/claim + issuance actions/payments/partners. Every endpoint is
 * permission-gated and scoped server-side to the caller's own partner or
 * carrier (see routes/wave16_partner.php).
 */
import { api, AgentClient } from "./client";
import { formatDisplayDate } from "@/i18n";

// ------------------------------------------------------------------ shared
export type PartnerQuote = {
  id: string;
  customer_id: string | null;
  customer_name: string;
  line_code: string;
  status: string;
  channel: string;
  offers: number;
  best_premium_minor: number | null;
  currency: string;
  expires_at: string | null;
  created_at: string | null;
};
export type PartnerPolicy = {
  id: string;
  policy_number: string | null;
  customer_name: string;
  carrier_id: string;
  carrier_name: string;
  line_code: string | null;
  premium_minor: number;
  currency: string;
  status: string;
  coverage_starts_at: string | null;
  coverage_ends_at: string | null;
  issued_at: string | null;
};
export type PartnerClaim = {
  id: string;
  claim_number: string;
  policy_id: string;
  policy_number: string | null;
  customer_name: string;
  status: string;
  priority: string;
  estimated_loss_minor: number | null;
  approved_amount_minor: number | null;
  currency: string;
  loss_occurred_at: string | null;
  submitted_at: string | null;
};

export const money = (minor?: number | null) =>
  `${new Intl.NumberFormat("fr-CM").format(Math.round((minor ?? 0) / 100))} FCFA`;
export const shortDate = (iso?: string | null) =>
  formatDisplayDate(iso);
export const humanize = (s: string) =>
  s.charAt(0) + s.slice(1).toLowerCase().replaceAll("_", " ");

// ------------------------------------------------------------------- agent
/** REQ-CRM-001 pipeline: NEW → CONTACTED → QUALIFIED → QUOTE → NEGOTIATION → WON (CONVERTED) / LOST. */
export type LeadStatus = "NEW" | "CONTACTED" | "QUALIFIED" | "QUOTE" | "NEGOTIATION" | "CONVERTED" | "LOST";
export type AgentLead = {
  id: string;
  full_name: string;
  phone_e164: string;
  city: string | null;
  product_interest: string | null;
  notes: string | null;
  status: LeadStatus;
  /** Allowed manual moves from the server (LeadPipeline::next); never CONVERTED. */
  next_statuses?: LeadStatus[];
  lost_reason?: string | null;
  converted_customer_id: string | null;
  created_at: string;
  updated_at: string;
};
export type ConsentedClient = AgentClient & { consent_reference: string };

const post = (body?: unknown) => ({
  method: "POST",
  body: body === undefined ? undefined : JSON.stringify(body),
  idempotent: true,
});

export const AgentWorkspaceApi = {
  leads: () => api<AgentLead[]>("/mobile/partner/agent/leads"),
  lead: (id: string) => api<AgentLead>(`/mobile/partner/agent/leads/${id}`),
  createLead: (payload: {
    full_name: string;
    phone_e164: string;
    city?: string;
    product_interest?: string;
    notes?: string;
  }) => api<AgentLead>("/mobile/partner/agent/leads", post(payload)),
  updateLead: (
    id: string,
    payload: { status?: Exclude<LeadStatus, "CONVERTED">; notes?: string; lost_reason?: string | null },
  ) =>
    api<AgentLead>(`/mobile/partner/agent/leads/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  /** consent_confirmed must be an explicit agent action; the server mints the consent reference. */
  convertLead: (id: string, payload: { consent_confirmed: true; city?: string }) =>
    api<{ lead: AgentLead; client: ConsentedClient }>(
      `/mobile/partner/agent/leads/${id}/convert`,
      post(payload),
    ),
  createClient: (payload: {
    full_name: string;
    phone_e164: string;
    city: string;
    consent_confirmed: true;
  }) => api<ConsentedClient>("/mobile/partner/agent/clients", post(payload)),
  quotes: () => api<PartnerQuote[]>("/mobile/partner/agent/quotes"),
  policies: () => api<PartnerPolicy[]>("/mobile/partner/agent/policies"),
};

// ------------------------------------------------------------------ broker
export type BrokerStaffMember = {
  membership_id: string;
  user_id: string;
  full_name: string;
  phone_e164: string | null;
  role_code: string;
  status: string;
  is_me: boolean;
  since: string | null;
};
export type BrokerStaff = {
  can_invite: boolean;
  members: BrokerStaffMember[];
  pending_invitations: {
    id: string;
    recipient: string;
    role_code: string;
    expires_at: string;
  }[];
};
export type BrokerInvitation = {
  id: string;
  recipient: string;
  role_code: string;
  status: string;
  expires_at: string;
  invite_code: string;
};
export type BrokerCommissions = {
  totals: {
    pending_minor: number;
    available_minor: number;
    paid_minor: number;
    currency: string;
  };
  accruals: {
    id: string;
    policy_id: string;
    policy_number: string | null;
    customer_name: string | null;
    status: string;
    amount_minor: number;
    paid_minor: number;
    currency: string;
    available_at: string | null;
  }[];
  statements: {
    id: string;
    statement_number: string;
    status: string;
    currency: string;
    period_start: string;
    period_end: string;
    earned_minor: number;
    paid_minor: number;
    closing_balance_minor: number;
  }[];
};

export const BrokerWorkspaceApi = {
  quotes: () => api<PartnerQuote[]>("/mobile/partner/broker/quotes"),
  policies: () => api<PartnerPolicy[]>("/mobile/partner/broker/policies"),
  claims: () => api<PartnerClaim[]>("/mobile/partner/broker/claims"),
  staff: () => api<BrokerStaff>("/mobile/partner/broker/staff"),
  inviteStaff: (payload: { recipient_phone_e164?: string; recipient_email?: string }) =>
    api<BrokerInvitation>("/mobile/partner/broker/staff/invitations", post(payload)),
  commissions: () => api<BrokerCommissions>("/mobile/partner/broker/commissions"),
};

// ----------------------------------------------------------------- insurer
export type CarrierProduct = {
  id: string;
  code: string;
  name: string;
  line_code: string;
  version: number;
  status: string;
  carrier_id: string;
  carrier_name: string | null;
  effective_from: string | null;
  effective_until: string | null;
  tariffs: { id: string; version: number; status: string; effective_from: string | null }[];
  policies_in_force: number;
  can_toggle: boolean;
};
export type CarrierProposal = {
  id: string;
  reference: string;
  status: string;
  quote_id: string;
  quote_status: string;
  customer_name: string;
  product: string;
  line_code: string;
  carrier_id: string;
  premium_minor: number;
  currency: string;
  submitted_at: string | null;
  created_at: string;
};
export type ClaimAction =
  | "acknowledge"
  | "request_information"
  | "propose_decision"
  | "approve_decision";
export type CarrierClaimDetail = PartnerClaim & {
  loss_location: string | null;
  description: string | null;
  carrier_id: string;
  actions: ClaimAction[];
  pending_decision: {
    id: string;
    decision: "APPROVE" | "PARTIAL" | "DECLINE";
    approved_amount_minor: number;
    reason_code: string;
    rationale: string;
    proposed_by_me: boolean;
    proposed_at: string | null;
  } | null;
  timeline: { to_status: string; reason_code: string; occurred_at: string }[];
};
export type CarrierPayment = {
  id: string;
  reference: string;
  proposal_number: string | null;
  customer_name: string;
  provider: string;
  amount_minor: number;
  currency: string;
  status: string;
  carrier_id: string;
  reconciliation_status: "RECONCILED" | "UNRECONCILED" | "EXCEPTION" | "NOT_APPLICABLE";
  exception_code: string | null;
  reconciled_at: string | null;
  created_at: string;
};
export type CarrierPayments = {
  summary: {
    succeeded_minor: number;
    reconciled_minor: number;
    unreconciled_count: number;
    exception_count: number;
    currency: string;
  };
  items: CarrierPayment[];
};
export type DistributionPartner = {
  id: string;
  name: string;
  type: string;
  status: string;
  licence_number: string | null;
  policies: number;
  premium_minor: number;
  agreement_number: string | null;
  agreement_status: string | null;
};

export const CarrierWorkspaceApi = {
  products: () => api<CarrierProduct[]>("/mobile/partner/carrier/products"),
  setProductActive: (id: string, active: boolean, reason: string) =>
    api<CarrierProduct>(`/mobile/partner/carrier/products/${id}/status`, post({ active, reason })),
  proposals: () => api<CarrierProposal[]>("/mobile/partner/carrier/proposals"),
  policies: () => api<PartnerPolicy[]>("/mobile/partner/carrier/policies"),
  claim: (id: string) => api<CarrierClaimDetail>(`/mobile/partner/carrier/claims/${id}`),
  acknowledgeClaim: (id: string) =>
    api<CarrierClaimDetail>(`/mobile/partner/carrier/claims/${id}/acknowledge`, post()),
  requestClaimInformation: (id: string, note: string) =>
    api<CarrierClaimDetail>(`/mobile/partner/carrier/claims/${id}/request-information`, post({ note })),
  proposeClaimDecision: (
    id: string,
    payload: {
      decision: "APPROVE" | "PARTIAL" | "DECLINE";
      approved_amount_minor?: number;
      reason_code: string;
      rationale: string;
    },
  ) => api<CarrierClaimDetail>(`/mobile/partner/carrier/claims/${id}/decisions`, post(payload)),
  approveClaimDecision: (id: string, decisionId: string) =>
    api<CarrierClaimDetail>(`/mobile/partner/carrier/claims/${id}/decisions/${decisionId}/approve`, post()),
  /** The policy number is always allocated by the server (read back from the response); only a carrier reference may be sent. */
  approveIssuance: (id: string, payload: { carrier_reference?: string } = {}) =>
    api<{ id: string; status: string; policy_id: string; policy_number: string; carrier_reference: string }>(
      `/mobile/partner/carrier/issuance/${id}/approve`,
      post(payload),
    ),
  rejectIssuance: (id: string, reason: string) =>
    api<{ id: string; status: string; rejection_reason: string }>(
      `/mobile/partner/carrier/issuance/${id}/reject`,
      post({ reason }),
    ),
  payments: () => api<CarrierPayments>("/mobile/partner/carrier/payments"),
  partners: () => api<DistributionPartner[]>("/mobile/partner/carrier/partners"),
};
