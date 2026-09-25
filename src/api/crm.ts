/**
 * Batch 4 contracts: lead activities + broker lead directory (REQ-CRM-001),
 * beneficiaries (REQ-CRM-004), global search (REQ-SRC-001), insured-object
 * types and Customer 360. All scoped server-side.
 */
import { api } from "./client";
import type { AgentLead } from "./partner";
import type { beneficiaryPayload, BeneficiaryDraft, LeadActivityType, RiskAssetType, SearchResponse, SearchType } from "@/lib/crm";

export type LeadActivity = {
  id: string;
  entry_type: LeadActivityType;
  body: string;
  author_id: string | null;
  follow_up_at: string | null;
  follow_up_notified_at: string | null;
  created_at: string | null;
};
export type DirectoryLead = AgentLead & {
  source: string | null;
  partner_id: string | null;
  assigned_user_id: string | null;
  status_changed_at: string | null;
};
export type Beneficiary = {
  id: string;
  set_version: number;
  designation: "PRIMARY" | "CONTINGENT";
  party_id: string | null;
  full_name: string | null;
  relationship: string | null;
  date_of_birth: string | null;
  allocation_pct: number;
  revocable: boolean;
  status: string;
  effective_from: string | null;
  effective_to: string | null;
};
export type BeneficiarySet = {
  set_version: number;
  designations: Beneficiary[];
  reason?: string | null;
  effective_from?: string | null;
  effective_to?: string | null;
  [k: string]: unknown;
};
export type Customer360 = {
  party: { id: string; type: string; display_name: string; status: string; merged_into_id: string | null };
  customer: { id: string; customer_number: string | null; status: string };
  roles: { id: string; role_code: string }[];
  policies: { id: string; policy_number: string | null; certificate_number: string | null; status: string; coverage_starts_at: string | null; coverage_ends_at: string | null }[];
  claims: { id: string; claim_number: string; status: string; policy_id: string; loss_occurred_at: string | null }[];
  beneficiaries: { id: string; designation: string; full_name?: string | null; allocation_pct?: number; restricted?: boolean }[];
  kyc: { id: string; status: string; submitted_at: string | null; created_at: string }[];
  documents: { id: string; category: string; verification_status: string | null; created_at: string }[];
  leads: { id: string; status: string; product_interest: string | null; created_at: string }[];
  counts: { policies: number; claims: number; documents: number };
  timeline: { at: string; kind: string; id: string; label: string }[];
};

const json = (method: string, body?: unknown) => ({
  method,
  body: body === undefined ? undefined : JSON.stringify(body),
  idempotent: true,
});

export const LeadActivityApi = {
  agentList: (leadId: string) => api<LeadActivity[]>(`/mobile/partner/agent/leads/${leadId}/activities`),
  agentAdd: (leadId: string, payload: { entry_type: LeadActivityType; body: string; follow_up_at?: string | null }) =>
    api<LeadActivity>(`/mobile/partner/agent/leads/${leadId}/activities`, json("POST", payload)),
};

export const LeadDirectoryApi = {
  list: (filters: { status?: string; q?: string } = {}) => {
    const qs = new URLSearchParams();
    if (filters.status) qs.set("status", filters.status);
    if (filters.q) qs.set("q", filters.q);
    const s = qs.toString();
    return api<DirectoryLead[]>(`/crm/leads${s ? `?${s}` : ""}`);
  },
  show: (id: string) => api<DirectoryLead & { activities: LeadActivity[] }>(`/crm/leads/${id}`),
  transition: (id: string, status: string, lost_reason?: string) =>
    api<DirectoryLead>(`/crm/leads/${id}/transitions`, json("POST", { status, ...(lost_reason ? { lost_reason } : {}) })),
  addActivity: (id: string, payload: { entry_type: LeadActivityType; body: string; follow_up_at?: string | null }) =>
    api<LeadActivity>(`/crm/leads/${id}/activities`, json("POST", payload)),
  assign: (id: string, payload: { partner_id: string | null; assigned_user_id?: string | null; reason: string }) =>
    api<DirectoryLead>(`/crm/leads/${id}/assign`, json("POST", payload)),
};

export const BeneficiaryApi = {
  list: (policyId: string) => api<Beneficiary[]>(`/policies/${policyId}/beneficiaries`),
  history: (policyId: string) => api<BeneficiarySet[]>(`/policies/${policyId}/beneficiaries/history`),
  replace: (
    policyId: string,
    payload: { beneficiaries: ReturnType<typeof beneficiaryPayload>; reason: string },
  ) => api<Beneficiary[]>(`/policies/${policyId}/beneficiaries`, json("PUT", payload)),
};
export type { BeneficiaryDraft };

export const SearchApi = {
  search: (q: string, types: SearchType[] = [], limit = 5) => {
    const qs = new URLSearchParams({ q, limit: String(limit) });
    for (const t of types) qs.append("types[]", t);
    return api<SearchResponse>(`/search?${qs.toString()}`);
  },
};

export const RiskAssetTypesApi = {
  list: () => api<RiskAssetType[]>("/risk-asset-types"),
};

export const Customer360Api = {
  overview: (partyId: string) => api<Customer360>(`/customers/${partyId}/overview`),
};
