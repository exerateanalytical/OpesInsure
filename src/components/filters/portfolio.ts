/**
 * Customer-portfolio filters shared by the agent and broker client lists
 * (FLT-001/002/003/004, CUST-001/002). Sections are only offered when the
 * loaded data can honour them; server-only dimensions (customer type,
 * producer) are sent as query params and listed in the backend needs until
 * the list endpoints return the fields.
 */
import { optionsFrom, type FilterSection, type Matchers } from "@/components/filters";
import type { PartnerPolicy } from "@/api/partner";
import type { useTranslation } from "@/i18n";

export type PortfolioClient = {
  id: string;
  full_name: string;
  phone_e164?: string | null;
  city?: string | null;
  origin_locked?: boolean;
  kyc_status?: string | null;
  policies?: number;
  active_policies?: number;
  outstanding_minor?: number;
  renewal_due_at?: string | null;
  /** Optional server fields (backend need): honoured as soon as they are returned. */
  customer_type?: string | null;
  ownership?: string | null;
  producer_user_id?: string | null;
  producer_name?: string | null;
  carrier_ids?: string[] | null;
  line_codes?: string[] | null;
  product_ids?: string[] | null;
};

type Tr = Pick<ReturnType<typeof useTranslation>, "t" | "td">;

const norm = (s?: string | null) => (s ?? "").trim().toLowerCase();

/** Client -> policies: by customer_id when the policy row carries it, else by customer name. */
export function policiesByCustomer(policies: PartnerPolicy[]) {
  const m = new Map<string, PartnerPolicy[]>();
  const add = (k: string, p: PartnerPolicy) => m.set(k, [...(m.get(k) ?? []), p]);
  for (const p of policies) {
    if (p.customer_id) add(`id:${p.customer_id}`, p);
    else add(`name:${norm(p.customer_name)}`, p);
  }
  return m;
}
const clientPolicies = (by: Map<string, PartnerPolicy[]>, c: PortfolioClient) => [...(by.get(`id:${c.id}`) ?? []), ...(by.get(`name:${norm(c.full_name)}`) ?? [])];

const daysUntil = (iso?: string | null) => (iso ? Math.floor((new Date(iso).getTime() - Date.now()) / 86_400_000) : null);

export function portfolioSections(rows: PortfolioClient[], policies: PartnerPolicy[], tr: Tr): FilterSection[] {
  const { t, td } = tr;
  const by = policiesByCustomer(policies);
  const joined = rows.flatMap((c) => clientPolicies(by, c));
  const sections: FilterSection[] = [
    {
      key: "carrier_id",
      title: t("fltInsurer"),
      options: optionsFrom(joined, (p) => ({ value: p.carrier_id, label: p.carrier_name })),
    },
    {
      key: "line_code",
      title: t("fltProductFamily"),
      options: optionsFrom(joined, (p) => (p.line_code ? { value: p.line_code, label: td(`line_${p.line_code}`, p.line_code) } : null)),
    },
    {
      key: "policy_status",
      title: t("fltPolicyStatus"),
      options: optionsFrom(joined, (p) => ({ value: p.status, label: td(`policyStatus_${p.status}`, p.status) })),
    },
    {
      key: "customer_type",
      title: t("fltCustomerType"),
      options: optionsFrom(rows, (r) => (r.customer_type ? { value: r.customer_type, label: td(`customerType_${r.customer_type}`, r.customer_type) } : null)),
    },
    {
      key: "ownership",
      title: t("fltOwnership"),
      options: rows.some((r) => r.ownership)
        ? optionsFrom(rows, (r) => (r.ownership ? { value: r.ownership, label: td(`ownership_${r.ownership}`, r.ownership) } : null))
        : [
            { value: "LOCKED", label: t("fltOriginLocked") },
            { value: "UNLOCKED", label: t("fltOriginOpen") },
          ],
    },
    {
      key: "producer_user_id",
      title: t("fltProducer"),
      options: optionsFrom(rows, (r) => (r.producer_user_id ? { value: r.producer_user_id, label: r.producer_name ?? r.producer_user_id } : null)),
    },
    {
      key: "renewal_window",
      title: t("fltRenewalWindow"),
      options: [30, 60, 90].map((d) => ({ value: String(d), label: t("fltWithinDays", { days: d }) })),
    },
    {
      key: "payment_state",
      title: t("fltPaymentState"),
      options: rows.some((r) => r.outstanding_minor !== undefined)
        ? [
            { value: "OUTSTANDING", label: t("fltOutstanding") },
            { value: "CLEAR", label: t("fltSettled") },
          ]
        : [],
    },
    {
      key: "kyc_status",
      title: t("fltKyc"),
      options: optionsFrom(rows, (r) => (r.kyc_status ? { value: r.kyc_status, label: td(`kycStatus_${r.kyc_status}`, r.kyc_status) } : null)),
    },
    {
      key: "city",
      title: t("fltCity"),
      options: optionsFrom(rows, (r) => (r.city ? { value: r.city, label: r.city } : null)),
    },
  ];
  return sections;
}

export function portfolioMatchers(policies: PartnerPolicy[]): Matchers<PortfolioClient> {
  const by = policiesByCustomer(policies);
  const pol = (c: PortfolioClient) => clientPolicies(by, c);
  return {
    carrier_id: (c, v) => (c.carrier_ids ? c.carrier_ids.includes(v) : pol(c).some((p) => p.carrier_id === v)),
    line_code: (c, v) => (c.line_codes ? c.line_codes.includes(v) : pol(c).some((p) => p.line_code === v)),
    policy_status: (c, v) => pol(c).some((p) => p.status === v),
    customer_type: (c, v) => c.customer_type === v,
    ownership: (c, v) => (c.ownership ? c.ownership === v : v === "LOCKED" ? !!c.origin_locked : !c.origin_locked),
    producer_user_id: (c, v) => c.producer_user_id === v,
    renewal_window: (c, v) => {
      const d = daysUntil(c.renewal_due_at);
      return d !== null && d >= 0 && d <= Number(v);
    },
    payment_state: (c, v) => (v === "OUTSTANDING" ? (c.outstanding_minor ?? 0) > 0 : (c.outstanding_minor ?? 0) === 0),
    kyc_status: (c, v) => c.kyc_status === v,
    city: (c, v) => c.city === v,
  };
}

export const portfolioHaystack = (c: PortfolioClient) => [c.full_name, c.phone_e164, c.city, c.id];

