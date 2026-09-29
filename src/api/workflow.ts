import { api, ApiError, Quote, QuoteOffer } from "./client";
import type { CatalogueItem, ChecklistDocument, QuoteComparison } from "@/lib/quoteWorkflow";

/** Batch 6 endpoints: quote workflow, manual quotation, proposal lifecycle, distribution catalogue. */

export type WorkflowQuote = Quote & {
  decline_reason_code?: string | null;
  declined_at?: string | null;
};

export type CarrierQuoteRequest = {
  id: string;
  request_number: string;
  status: string;
  quote_id: string;
  carrier_id: string;
  product_id: string | null;
  channel?: string | null;
  requested_at: string | null;
  response_due_at: string | null;
  responded_at: string | null;
  quote_offer_id: string | null;
  decline_reason_code: string | null;
  version?: number;
  risk_snapshot?: Record<string, unknown> | null;
  notes?: string | null;
  /** Case-engine fields (owner decisions #13/#32): present once the API sends them. */
  case_status?: string | null;
  case_family?: string | null;
  case_subtype?: string | null;
  sla?: { metric: string; due_at: string | null; stopped_at: string | null; breached_at: string | null; label?: string | null; deadline_label?: string | null }[];
  responses?: {
    id: string;
    response_type: string;
    source: string;
    premium_minor: number | null;
    tax_minor: number | null;
    fee_minor: number | null;
    total_minor: number | null;
    premium_breakdown?: { code: string; label?: string; amount_minor: number }[] | null;
    conditions?: { code?: string; text: string }[] | null;
    valid_until: string | null;
    decline_reason_code: string | null;
    notes: string | null;
    responded_at: string | null;
  }[];
};

export type ProposalChecklist = {
  proposal_id: string;
  status: string;
  version?: number;
  required_documents: ChecklistDocument[];
  information_request?: { id: string; items: { code?: string; description: string }[]; message?: string | null; requested_at?: string; responded_at?: string | null } | null;
  available_transitions?: string[];
  blocking?: string[];
  /** Declarations catalogue with this application's acceptance (TERMS_ACCEPTANCE once the terms screen was confirmed). */
  declarations?: { code: string; accepted?: boolean; required_for_submit?: boolean }[];
  submission_count?: number;
  /** Chosen cover terms (null = defaults: start at issuance, 12 months, single payment). */
  cover_terms?: CoverTerms | null;
  /** What this product allows (CoverTermsService::ruleFor). */
  cover_term_rule?: CoverTermRule | null;
};
export type CoverTerms = { effective_rule: string; start_date?: string | null; ends_before?: string | null; duration?: { unit: string; value?: number | null }; instalment_plan?: string };
export type CoverTermRule = { effective_date_rules: string[]; default_effective_rule: string; max_advance_days: number; durations?: { unit: string; value?: number }[]; instalment_plans?: string[] };

const post =(body?: unknown) => ({ method: "POST", body: body === undefined ? undefined : JSON.stringify(body), idempotent: true }) as const;

export const QuoteWorkflowApi = {
  /** Canonical GET quotes/{q}: {quote (lifecycle_state, quote_number), offers}. */
  show: (id: string) => api<{ quote: WorkflowQuote; offers: QuoteOffer[] }>(`/quotes/${id}`),
  /** PATCH quotes/{q} (WF-010): new risk facts, live offers superseded, quote back to DRAFT for re-rating. */
  amend: (id: string, risk_facts: Record<string, unknown>, point: { latitude?: number; longitude?: number } = {}) =>
    api<unknown>(`/quotes/${id}`, { method: "PATCH", body: JSON.stringify({ risk_facts, ...point }), idempotent: true }),
  decline: (id: string, reason_code: string, note?: string) => api<WorkflowQuote>(`/quotes/${id}/decline`, post({ reason_code, ...(note?.trim() ? { note: note.trim() } : {}) })),
  carrierRequests: (quoteId: string) => api<CarrierQuoteRequest[]>(`/quotes/${quoteId}/carrier-requests`),
  compare: (quote_id: string, offer_ids?: string[]) => api<QuoteComparison>("/quote-comparisons", post({ quote_id, ...(offer_ids?.length ? { offer_ids } : {}) })),
  comparisons: (quoteId: string) => api<QuoteComparison[]>(`/quote-comparisons?quote_id=${encodeURIComponent(quoteId)}`),
  comparison: (id: string) => api<QuoteComparison>(`/quote-comparisons/${id}`),
  /** GET quotes/{q}/document is a raw PDF (not the JSON envelope); 422 until the quote is generated. */
  async document(id: string): Promise<{ dataUri: string; bytes: number }> {
    const blob = await api<Blob>(`/quotes/${id}/document`, { raw: true, headers: { Accept: "application/pdf" }, timeoutMs: 30000 });
    const dataUri = await new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new ApiError(0, "DOCUMENT_UNAVAILABLE", "The document could not be read."));
      reader.onloadend = () => resolve(String(reader.result ?? "").replace(/^data:[^,;]*/, "data:application/pdf"));
      reader.readAsDataURL(blob);
    });
    return { dataUri, bytes: blob.size };
  },
};

export const CarrierQuoteRequestsApi = {
  list: (open = true) => api<CarrierQuoteRequest[]>(`/carrier/quote-requests${open ? "" : "?open=0"}`),
  show: (id: string) => api<CarrierQuoteRequest>(`/carrier/quote-requests/${id}`),
  start: (id: string) => api<CarrierQuoteRequest>(`/carrier/quote-requests/${id}/start`, post()),
  offer: (id: string, body: Record<string, unknown>) => api<CarrierQuoteRequest>(`/carrier/quote-requests/${id}/offer`, post({ ...body, source: "INSURER_PORTAL" })),
  decline: (id: string, decline_reason_code: string, notes?: string) =>
    api<CarrierQuoteRequest>(`/carrier/quote-requests/${id}/decline`, post({ decline_reason_code, ...(notes?.trim() ? { notes: notes.trim() } : {}), source: "INSURER_PORTAL" })),
};

export const ProposalLifecycleApi = {
  checklist: (id: string) => api<ProposalChecklist>(`/proposals/${id}/checklist`),
  resubmit: (id: string, response?: string) => api<unknown>(`/proposals/${id}/resubmit`, post(response?.trim() ? { response: response.trim() } : {})),
  withdraw: (id: string, reason?: string) => api<unknown>(`/proposals/${id}/withdraw`, post(reason?.trim() ? { reason: reason.trim() } : {})),
  /** PUT proposals/{p}/cover-terms — while the application is still being completed (ANSWERABLE). */
  setCoverStart: (id: string, effective_rule: "IMMEDIATE" | "SPECIFIED_DATE", start_date?: string) =>
    api<CoverTerms>(`/proposals/${id}/cover-terms`, { method: "PUT", body: JSON.stringify({ effective_rule, ...(start_date ? { start_date } : {}) }), idempotent: true }),
};

export const DistributionApi = {
  /** Include blocked products so the seller sees why something is not sellable. */
  catalogue: () => api<CatalogueItem[]>("/distribution/catalogue?include_blocked=1"),
};
