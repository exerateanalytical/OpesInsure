/**
 * "Take the customer to payment when it is time": pure navigation decisions
 * for applications (proposals) that become payable. Used by the application
 * hub (auto-forward once per visit), the disclosure questions, the terms
 * screen, Home ("Needs your attention" + "In progress") and notifications.
 *
 * Server facts only: an application is payable when its status maps to the
 * "payable" stage (APPROVED, PAYMENT_PENDING) and no policy was issued yet.
 * Whether the terms were already accepted comes from the proposal checklist
 * (declarations[].code === "TERMS_ACCEPTANCE"); when unknown the customer
 * goes to the terms screen (accepting twice is harmless server-side).
 *
 * Dependency-free: tests/payment-routing.test.mjs loads it with Node's type
 * stripping.
 */
import { proposalStatusInfo } from "./purchase.ts";

export type PurchaseStep = "terms" | "checkout";
export type PurchaseRoute =
  | { pathname: "/quote/terms" | "/checkout"; params: { proposalId: string; approved?: "1" } }
  | { pathname: "/proposals/[id]"; params: { id: string } }
  | { pathname: "/quote/referral"; params: { proposalId: string } };

const upper = (v: string | null | undefined) => String(v ?? "").toUpperCase();

/** True when the application waits for the customer's payment (no policy issued yet). */
export function isPayable(status: string | null | undefined, policyId?: string | null): boolean {
  if (policyId) return false;
  if (!upper(status)) return false;
  return proposalStatusInfo(status).stage === "payable";
}

/** Next purchase step: null unless payable; terms first unless they were already accepted. */
export function nextPurchaseStep(status: string | null | undefined, termsAccepted?: boolean | null, policyId?: string | null): PurchaseStep | null {
  if (!isPayable(status, policyId)) return null;
  return termsAccepted ? "checkout" : "terms";
}

/** Route of a purchase step; `approved` shows the "your application is approved" banner there. */
export function purchaseRoute(proposalId: string, step: PurchaseStep, approved = false): PurchaseRoute {
  return {
    pathname: step === "checkout" ? "/checkout" : "/quote/terms",
    params: approved ? { proposalId, approved: "1" } : { proposalId },
  };
}

/** TERMS_ACCEPTANCE accepted in the checklist declarations (GET proposals/{id}/checklist). */
export function termsAcceptedIn(declarations: { code?: string | null; accepted?: boolean | null }[] | null | undefined): boolean {
  return (declarations ?? []).some((d) => upper(d?.code) === "TERMS_ACCEPTANCE" && d?.accepted === true);
}

/**
 * Application hub: where to forward automatically, or null. Only once per
 * visit (`forwarded`), so coming back with the hardware back never loops.
 */
export function hubForward(input: { proposalId: string; status: string | null | undefined; policyId?: string | null; termsAccepted?: boolean | null; forwarded: boolean }): PurchaseRoute | null {
  if (input.forwarded || !input.proposalId) return null;
  const step = nextPurchaseStep(input.status, input.termsAccepted, input.policyId);
  return step ? purchaseRoute(input.proposalId, step, true) : null;
}

/** After the disclosure answers were submitted: referral, straight to payment when payable, else the hub. */
export function afterDisclosureRoute(proposalId: string, status: string | null | undefined): PurchaseRoute {
  if (upper(status) === "REFERRED") return { pathname: "/quote/referral", params: { proposalId } };
  const step = nextPurchaseStep(status);
  return step ? purchaseRoute(proposalId, step, true) : { pathname: "/proposals/[id]", params: { id: proposalId } };
}

/**
 * After the terms were accepted (POST proposals/{id}/terms returns the new
 * status; it submits a DOCUMENTS_PENDING application): checkout when payable,
 * otherwise the application hub (e.g. now under review). An older server that
 * returns no status keeps the previous behaviour (checkout).
 */
export function afterTermsRoute(proposalId: string, status: string | null | undefined): PurchaseRoute {
  if (!upper(status) || isPayable(status)) return purchaseRoute(proposalId, "checkout");
  return { pathname: "/proposals/[id]", params: { id: proposalId } };
}

/**
 * A DOCUMENTS_PENDING application whose checklist no longer blocks on
 * documents, KYC, attestation or a declaration other than the terms: the
 * terms screen is the next step (accepting the terms submits it; a
 * straight-through application comes back payable and goes to checkout).
 * Unknown checklist (older server) = not ready: the hub keeps its refresh.
 */
export function readyForTerms(status: string | null | undefined, blocking: string[] | null | undefined): boolean {
  if (upper(status) !== "DOCUMENTS_PENDING" || !Array.isArray(blocking)) return false;
  return blocking.every((b) => upper(b) === "DECLARATION_REQUIRED:TERMS_ACCEPTANCE");
}

type PaymentLike = { id: string; proposal_id?: string | null; status?: string | null; created_at?: string | null };
type ProposalLike = { id: string; status?: string | null; policy_id?: string | null; updated_at?: string | null; created_at?: string | null };

/** Latest payment attempt statuses that already have their own "Needs your attention" row. */
const ATTEMPT_SHOWN = new Set(["FAILED", "EXPIRED", "CREATED", "PENDING_CUSTOMER", "PROCESSING", "SUCCEEDED"]);

/**
 * Applications waiting for payment, newest first. An application whose
 * latest payment attempt is in flight, failed or succeeded is left out: the
 * payment row (or issuance) already covers it.
 */
export function payableApplications<P extends ProposalLike>(proposals: P[] | null | undefined, payments?: PaymentLike[] | null): P[] {
  const latest = new Map<string, PaymentLike>();
  for (const p of payments ?? []) {
    if (!p.proposal_id) continue;
    const cur = latest.get(p.proposal_id);
    if (!cur || String(p.created_at ?? "") > String(cur.created_at ?? "")) latest.set(p.proposal_id, p);
  }
  return (proposals ?? [])
    .filter((p) => isPayable(p.status, p.policy_id) && !ATTEMPT_SHOWN.has(upper(latest.get(p.id)?.status)))
    .sort((a, b) => String(b.updated_at ?? b.created_at ?? "").localeCompare(String(a.updated_at ?? a.created_at ?? "")));
}
