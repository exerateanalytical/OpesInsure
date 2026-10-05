/**
 * Phase-1 fix S (2026-09-30): staff workspace claims (mobile/workspace/claims*), cursor-paged staff lists and the
 * per-id reads that replace "find the record in the first page of the list" (useListRecord).
 */
import { api } from "./client";
import type { BrokerComplianceItem, BrokerProduction, CarrierQueueItem, CarrierReferral, WorkspaceModuleData } from "./client";
import type { CarrierPayment, CarrierPayments, CarrierProduct, CarrierProposal, DistributionPartner, PartnerPolicy } from "./partner";
import { cursorPager, nextCursorOf, withCursor, type CursorPage } from "@/lib/cursorPages";

const post = (body?: unknown) => ({ method: "POST", body: body === undefined ? undefined : JSON.stringify(body), idempotent: true });

/** Page size the app asks for (the server caps it). */
export const STAFF_PAGE_SIZE = 50;

async function page<T>(path: string, cursor: string | null, pick: (data: unknown) => T[] = (d) => (Array.isArray(d) ? (d as T[]) : [])): Promise<CursorPage<T>> {
  const payload = await api<{ data?: unknown }>(withCursor(path, cursor, STAFF_PAGE_SIZE), { envelope: true });
  return { items: pick(payload?.data), next: nextCursorOf(payload) };
}

// ------------------------------------------------------------ workspace claims

export type WorkspaceClaim = {
  id: string;
  claim_number: string;
  status: string;
  status_label: string;
  priority: string | null;
  claimant_name: string | null;
  policy_number: string | null;
  estimated_loss_minor: number | null;
  currency: string;
  submitted_at: string | null;
  loss_occurred_at: string | null;
  assigned_to_me: boolean;
  assignee_name: string | null;
};

export type WorkspaceExpertAssignment = {
  id: string;
  status: string;
  status_label: string;
  available_events: string[];
  instructions: string | null;
  inspection_scheduled_for: string | null;
  inspection_location: string | null;
  report_submitted_at: string | null;
  assigned_at: string | null;
};

export type WorkspaceClaimDetail = WorkspaceClaim & {
  loss_location: string | null;
  description: string | null;
  current_reserve_minor: number | null;
  approved_amount_minor: number | null;
  actions: string[];
  transitions: { to_status: string; label: string }[];
  pending_decision: {
    id: string;
    decision: string;
    approved_amount_minor: number;
    reason_code: string;
    rationale: string;
    proposed_by_me: boolean;
    proposed_at: string | null;
  } | null;
  expert_assignments: WorkspaceExpertAssignment[];
  timeline: { to_status: string; label: string; reason_code: string | null; occurred_at: string }[];
};

export const WorkspaceClaimsApi = {
  pager: (filters: { status?: string | null; q?: string | null }) => {
    const qs = [filters.status ? `status=${encodeURIComponent(filters.status)}` : "", filters.q?.trim() ? `q=${encodeURIComponent(filters.q.trim())}` : ""].filter(Boolean).join("&");
    return cursorPager<WorkspaceClaim>((cursor) => page<WorkspaceClaim>(`/mobile/workspace/claims${qs ? `?${qs}` : ""}`, cursor));
  },
  claim: (id: string) => api<WorkspaceClaimDetail>(`/mobile/workspace/claims/${encodeURIComponent(id)}`),
  assignToMe: (id: string) => api<WorkspaceClaimDetail>(`/mobile/workspace/claims/${encodeURIComponent(id)}/assign-to-me`, post({})),
  transition: (id: string, toStatus: string, note?: string) =>
    api<WorkspaceClaimDetail>(`/mobile/workspace/claims/${encodeURIComponent(id)}/transitions`, post({ to_status: toStatus, ...(note?.trim() ? { note: note.trim() } : {}) })),
  proposeDecision: (id: string, payload: { decision: "APPROVE" | "PARTIAL" | "DECLINE"; approved_amount_minor?: number; reason_code: string; rationale: string }) =>
    api<WorkspaceClaimDetail>(`/mobile/workspace/claims/${encodeURIComponent(id)}/decisions`, post(payload)),
  approveDecision: (id: string, decisionId: string) =>
    api<WorkspaceClaimDetail>(`/mobile/workspace/claims/${encodeURIComponent(id)}/decisions/${encodeURIComponent(decisionId)}/approve`, post({})),
  /** Adjuster moves on their own expert assignment (existing claims/adjuster/assignments/* endpoints). */
  adjusterMove: (path: string, body: Record<string, unknown>) => api<unknown>(path, post(body)),
};

/** Generic workspace module table, cursor-paged. */
export const workspaceModulePager = (key: string) => {
  let meta: Pick<WorkspaceModuleData, "title" | "label" | "columns"> | null = null;
  const pager = cursorPager<Record<string, unknown> & { id: string }>(async (cursor) => {
    const payload = await api<{ data?: WorkspaceModuleData }>(withCursor(`/mobile/workspace/modules/${encodeURIComponent(key)}`, cursor, STAFF_PAGE_SIZE), { envelope: true });
    const data = payload?.data;
    if (data) meta = { title: data.title, label: data.label, columns: data.columns };
    // Rows carry their record id (newer servers); older ones fall back to the page cursor + row position.
    const items = (data?.rows ?? []).map((r, i) => ({ ...r, id: typeof r.id === "string" && r.id ? r.id : `row-${cursor ?? "first"}-${i}` }));
    return { items, next: nextCursorOf(payload) };
  });
  return { pager, meta: () => meta };
};

// ------------------------------------------------------ paged staff lists / detail

export const CarrierPagedApi = {
  products: () => cursorPager<CarrierProduct>((c) => page("/mobile/partner/carrier/products", c)),
  product: (id: string) => api<CarrierProduct>(`/mobile/partner/carrier/products/${encodeURIComponent(id)}`),
  proposals: () => cursorPager<CarrierProposal>((c) => page("/mobile/partner/carrier/proposals", c)),
  proposal: (id: string) => api<CarrierProposal>(`/mobile/partner/carrier/proposals/${encodeURIComponent(id)}`),
  policies: () => cursorPager<PartnerPolicy>((c) => page("/mobile/partner/carrier/policies", c)),
  policy: (id: string) => api<PartnerPolicy>(`/mobile/partner/carrier/policies/${encodeURIComponent(id)}`),
  payments: () => cursorPager<CarrierPayment>((c) => page("/mobile/partner/carrier/payments", c, (d) => ((d as CarrierPayments | undefined)?.items ?? []))),
  payment: (id: string) => api<CarrierPayment>(`/mobile/partner/carrier/payments/${encodeURIComponent(id)}`),
  partners: () => cursorPager<DistributionPartner>((c) => page("/mobile/partner/carrier/partners", c)),
  partner: (id: string) => api<DistributionPartner>(`/mobile/partner/carrier/partners/${encodeURIComponent(id)}`),
  claims: () => cursorPager<CarrierQueueItem>((c) => page("/mobile/carrier/claims", c)),
  referrals: () => cursorPager<CarrierReferral>((c) => page("/mobile/carrier/referrals", c)),
  issuance: () => cursorPager<CarrierQueueItem>((c) => page("/mobile/carrier/issuance", c)),
};

export type BrokerReceivable = { id: string; customer_name: string; amount_minor: number; currency: "XAF"; status: string; due_at: string; policy_number?: string | null; label?: string | null };

export const BrokerPagedApi = {
  production: () => cursorPager<BrokerProduction>((c) => page("/mobile/broker/production", c)),
  productionItem: (id: string) => api<BrokerProduction>(`/mobile/broker/production/${encodeURIComponent(id)}`),
  receivables: () => cursorPager<BrokerReceivable>((c) => page("/mobile/broker/receivables", c)),
  receivable: (id: string) => api<BrokerReceivable>(`/mobile/broker/receivables/${encodeURIComponent(id)}`),
  complianceItem: (id: string) => api<BrokerComplianceItem>(`/mobile/broker/compliance/${encodeURIComponent(id)}`),
};
