import { api } from "./client";

/**
 * API methods added by the audit remediation (Phase 4). Kept out of
 * client.ts so auth/session work there can proceed independently.
 *
 * Several finance endpoints return a Laravel paginator under `data`
 * ({ data: [...], current_page, ... }); `rows()` flattens either shape.
 */
type Page<T> = T[] | { data?: T[] };
export const rows = <T>(page: Page<T> | null | undefined): T[] =>
  Array.isArray(page) ? page : (page?.data ?? []);

// --- Broker finance ------------------------------------------------------
export type BrokerStatement = {
  id: string;
  statement_number: string;
  period_start: string;
  period_end: string;
  currency: string;
  status: string;
  opening_balance_minor: number;
  earned_minor: number;
  clawed_back_minor: number;
  paid_minor: number;
  closing_balance_minor: number;
};
export type CommissionAccrual = {
  id: string;
  policy_id: string;
  amount_minor: number;
  vested_minor?: number;
  paid_minor?: number;
  currency: string;
  status: string;
  vests_at?: string | null;
  created_at: string;
};
export const BrokerFinanceApi = {
  statements: async () =>
    rows(await api<Page<BrokerStatement>>("/mobile/broker/statements")),
  accruals: async () =>
    rows(
      await api<Page<CommissionAccrual>>("/mobile/broker/commission-accruals"),
    ),
};

// --- Carrier finance -----------------------------------------------------
export type CarrierSettlementItem = {
  id: string;
  policy_id: string;
  gross_premium_minor: number;
  commission_minor: number;
  tax_minor: number;
  adjustment_minor: number;
  net_due_minor: number;
  currency: string;
  status: string;
};
export type CarrierSettlementDetail = {
  id: string;
  settlement_number?: string | null;
  period_start: string;
  period_end: string;
  net_amount_minor: number;
  currency: string;
  status: string;
  submitted_at?: string | null;
  paid_at?: string | null;
  bank_reference?: string | null;
  items: CarrierSettlementItem[];
};
export type Bordereau = {
  id: string;
  type: string;
  bordereau_number: string;
  period_start: string;
  period_end: string;
  status: string;
  item_count: number;
  gross_premium_minor: number;
  commission_minor: number;
  currency: string;
  submitted_at?: string | null;
};
export const CarrierFinanceApi = {
  settlement: (id: string) =>
    api<CarrierSettlementDetail>(`/mobile/carrier/settlements/${id}`),
  bordereaux: async () =>
    rows(await api<Page<Bordereau>>("/mobile/carrier/bordereaux")),
};

// --- Claims --------------------------------------------------------------
export type ClaimTimelineEvent = {
  id: string;
  type: string;
  from_status?: string | null;
  to_status?: string | null;
  occurred_at: string;
};
export type ClaimEvidenceItem = {
  id: string;
  document_id: string;
  evidence_type: string;
  status: string;
  submitted_at?: string | null;
  verified_at?: string | null;
  category?: string;
  mime_type?: string;
  size_bytes?: number;
  scan_status?: string;
};
export const ClaimRecordsApi = {
  timeline: async (id: string) =>
    rows(await api<Page<ClaimTimelineEvent>>(`/mobile/claims/${id}/timeline`)),
  evidence: async (id: string) =>
    rows(await api<Page<ClaimEvidenceItem>>(`/mobile/claims/${id}/evidence`)),
};

// --- Public institution directory ---------------------------------------
export type InstitutionProduct = { id: string; name: string; line_code: string };
export type Institution = {
  id: string;
  type: "insurer" | "broker";
  name: string;
  initials: string;
  city: string | null;
  code?: string;
  phone?: string | null;
  website?: string | null;
  products?: InstitutionProduct[];
  licence_number?: string | null;
  licence_expires_on?: string | null;
  // Official DGTCFM/MINFI register fields (null for non-register rows).
  canonical_id?: string | null;
  legal_name?: string | null;
  short_name?: string | null;
  insurer_code?: string | null;
  branch?: "IARD" | "LIFE" | null;
  regulator_sequence?: number | null;
  regulator_number?: number | null;
  product_families?: string[];
  product_families_status?: string | null;
  is_official_register?: boolean;
  is_demo?: boolean;
  data_origin?: string | null;
  source_authority?: string | null;
  reference_year?: number | null;
  regulatory_status?: string | null;
  licensed?: boolean;
};
export type InsuranceClassRow = {
  id: string;
  code: string;
  branch: "IARD" | "LIFE";
  name: { en: string; fr: string };
  sub_classes: { id: string; code: string; name: { en: string; fr: string } }[];
};
export const InstitutionsApi = {
  list: (type: "insurer" | "broker") =>
    api<Institution[]>(`/public/institutions?type=${type}`, { anonymous: true }),
  show: (id: string) =>
    api<Institution>(`/public/institutions/${id}`, { anonymous: true }),
  classes: () =>
    api<InsuranceClassRow[]>("/public/insurance-classes", { anonymous: true }),
};

/** Minor units → "12 345 FCFA". */
export const fcfa = (minor: number) =>
  `${new Intl.NumberFormat("fr-CM").format((minor ?? 0) / 100)} FCFA`;
