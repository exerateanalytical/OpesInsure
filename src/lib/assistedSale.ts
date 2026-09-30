/**
 * Agent assisted sale (app/agent/sales/*): pure helpers, no imports (node-tested in tests/agent-sale.test.mjs).
 * Every status comes from the server (AssistedSaleService); these only decide what the screen offers next.
 */

/** Lines an agent can price, same ids as the customer quote wizard (app/quote/product.tsx); line code = id uppercased. */
export const SALE_PRODUCTS = ["motor", "health", "travel", "home", "life", "business", "accident"] as const;
export type SaleProduct = (typeof SALE_PRODUCTS)[number];

/** `?product=` from the agent Home / catalogue: accepts the id or the line code (motor, MOTOR). */
export function saleProductFromParam(param: unknown): SaleProduct | null {
  const v = typeof param === "string" ? param.trim().toLowerCase() : "";
  return (SALE_PRODUCTS as readonly string[]).includes(v) ? (v as SaleProduct) : null;
}

export type Network = "mtn_momo" | "orange_money";

/** Cameroon numbering plan (same as the server): MTN 650-654, 67x, 680-684; Orange 655-659, 69x, 685-689. */
export function networkForPhone(phone: string | null | undefined): Network | null {
  const m = /^\+2376(\d{2})/.exec(String(phone ?? "").replace(/\s/g, ""));
  if (!m) return null;
  const n = Number(m[1]);
  if ((n >= 70 && n <= 79) || (n >= 50 && n <= 54) || (n >= 80 && n <= 84)) return "mtn_momo";
  if ((n >= 90 && n <= 99) || (n >= 55 && n <= 59) || (n >= 85 && n <= 89)) return "orange_money";
  return null;
}

export const isE164 = (phone: string | null | undefined) => /^\+[1-9]\d{7,14}$/.test(String(phone ?? ""));

/** The client is the insured person of an assisted sale (same shape the customer wizard sends for "me"). */
export function saleRiskFacts(facts: Record<string, unknown>): Record<string, unknown> {
  return { ...facts, insured_person: { relationship: "SELF" } };
}

type SaleLike = { next_action?: string | null; status?: string | null; payment_status?: string | null; proposal_id?: string | null; client_terms_accepted?: boolean | null; issuance_status?: string | null; policy_id?: string | null };

export type SaleButton = { labelKey: string; kind: "advance" | "refresh"; needsNetwork: boolean } | null;

/** The one footer action for the sale's server-reported next step. */
export function saleButton(sale: SaleLike | null | undefined): SaleButton {
  switch (sale?.next_action) {
    case "SEND_TO_CLIENT":
      return { labelKey: "slSendToClient", kind: "advance", needsNetwork: false };
    case "AWAIT_CLIENT":
      return { labelKey: "slRemindClient", kind: "advance", needsNetwork: false };
    case "REQUEST_PAYMENT":
      return { labelKey: "agSendPaymentRequest", kind: "advance", needsNetwork: true };
    case "RETRY_PAYMENT":
      return { labelKey: "slRetryPayment", kind: "advance", needsNetwork: true };
    case "AWAIT_UNDERWRITING":
    case "AWAIT_PAYMENT":
    case "AWAIT_ISSUANCE":
      return { labelKey: "slRefreshStatus", kind: "refresh", needsNetwork: false };
    default:
      return null;
  }
}

/** Poll while the server waits on the operator or on issuance. */
export const salePolls = (sale: SaleLike | null | undefined) => sale?.next_action === "AWAIT_PAYMENT" || sale?.next_action === "AWAIT_ISSUANCE";

/** Progress flags: quoted, sent to client, client accepted terms, payment requested, payment verified, issued. */
export function saleProgress(sale: SaleLike | null | undefined): boolean[] {
  if (!sale) return [false, false, false, false, false, false];
  const issued = sale.issuance_status === "ISSUED" || !!sale.policy_id || sale.status === "ISSUED";
  const paid = issued || sale.payment_status === "PAID";
  const requested = paid || (!!sale.payment_status && sale.payment_status !== "NOT_REQUESTED");
  const accepted = requested || !!sale.client_terms_accepted;
  const sent = accepted || !!sale.proposal_id;
  return [true, sent, accepted, requested, paid, issued];
}

export const SALE_PAYMENT_FAILED = ["FAILED", "EXPIRED", "CANCELLED"];
