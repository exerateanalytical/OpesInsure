/**
 * Commercial Agent Home: "Needs attention", pipeline counts and recent activity,
 * derived from the agent's own lists (the server already scopes each list to the book).
 * Pure functions, no imports (node-tested in tests/agent-home.test.mjs).
 */

type Row = { status?: string | null };
type Quote = Row & { id: string; customer_name: string; expires_at?: string | null; created_at?: string | null };
type Proposal = Row & { id: string; proposal_number: string; customer_name: string; customer_id?: string | null; policy_id?: string | null; submitted_at?: string | null; decided_at?: string | null; created_at?: string | null };
type Claim = Row & { id: string; claim_number: string; customer_name: string; submitted_at?: string | null };
type Lead = Row & { id: string; full_name: string; updated_at?: string | null; created_at?: string | null };
type Profile = { status?: string | null; mandate_expires_at?: string | null; compliance_items?: { status: string }[] } | null | undefined;

const up = (v: unknown) => String(v ?? "").toUpperCase();
const DAY = 86_400_000;

/** A priced quote still waiting on the client's decision. */
export const QUOTE_FOLLOW_UP = ["CALCULATED", "GENERATED", "SENT", "VIEWED", "OFFERED"];
/** The insurer sent the application back to the agent. */
export const PROPOSAL_RETURNED = ["INFORMATION_REQUIRED", "MORE_INFORMATION", "DOCUMENTS_PENDING", "DISCLOSURES_PENDING", "COUNTEROFFERED"];
/** Applications still moving through the insurer. */
export const PROPOSAL_IN_FLIGHT = ["SUBMITTED", "RESUBMITTED", "UNDER_REVIEW", "APPROVED", "PAYMENT_PENDING", "PAID"];
/** The claim waits on evidence the agent can help collect. */
export const CLAIM_INFO_REQUIRED = ["EVIDENCE_PENDING"];
export const CLAIM_CLOSED = ["CLOSED", "PAID", "DECLINED"];
export const LEAD_OPEN = ["NEW", "CONTACTED", "QUALIFIED", "QUOTE", "NEGOTIATION"];
/** Mandate expiring within this window is a KYC action. */
export const MANDATE_WARN_DAYS = 30;

const isQuoteOpen = (q: Quote, now: number) => QUOTE_FOLLOW_UP.includes(up(q.status)) && !(q.expires_at && Date.parse(q.expires_at) < now);

export function quoteFollowUps(quotes: Quote[] | null | undefined, now = Date.now()): number {
  return (quotes ?? []).filter((q) => isQuoteOpen(q, now)).length;
}

export const returnedApplications = (p: Proposal[] | null | undefined) => (p ?? []).filter((x) => PROPOSAL_RETURNED.includes(up(x.status))).length;

export const claimsNeedingInfo = (c: Claim[] | null | undefined) => (c ?? []).filter((x) => CLAIM_INFO_REQUIRED.includes(up(x.status))).length;

export const openClaims = (c: Claim[] | null | undefined) => (c ?? []).filter((x) => !CLAIM_CLOSED.includes(up(x.status)) && up(x.status) !== "DRAFT").length;

/** Missing/rejected KYC items, an inactive agent record, or a mandate about to lapse. */
export function kycActions(profile: Profile, now = Date.now()): number {
  if (!profile) return 0;
  let n = (profile.compliance_items ?? []).filter((i) => ["MISSING", "REJECTED", "EXPIRED"].includes(up(i.status))).length;
  if (up(profile.status) === "DRAFT" || up(profile.status) === "SUSPENDED") n = Math.max(n, 1);
  const exp = profile.mandate_expires_at ? Date.parse(profile.mandate_expires_at) : NaN;
  if (!Number.isNaN(exp) && exp - now < MANDATE_WARN_DAYS * DAY) n += 1;
  return n;
}

/** Count of a numeric dashboard metric ("Renewals due" -> 3). */
export function metricCount(metrics: { label: string; value: string }[] | null | undefined, label: string): number {
  const m = (metrics ?? []).find((x) => x.label === label);
  const n = m ? parseInt(String(m.value).replace(/\D/g, ""), 10) : 0;
  return Number.isFinite(n) ? n : 0;
}

export type Attention = "renewals" | "kyc" | "quotes" | "returned" | "claims";

/** Needs-attention items in the order the agent should handle them; zero counts are dropped. */
export function needsAttention(counts: Record<Attention, number>): { key: Attention; count: number }[] {
  const order: Attention[] = ["kyc", "returned", "claims", "renewals", "quotes"];
  return order.filter((k) => counts[k] > 0).map((k) => ({ key: k, count: counts[k] }));
}

export type PipelineStage = "leads" | "quotes" | "applications" | "returned";

export function pipelineCounts(
  input: { leads?: Lead[] | null; quotes?: Quote[] | null; proposals?: Proposal[] | null },
  now = Date.now(),
): Record<PipelineStage, number> {
  return {
    leads: (input.leads ?? []).filter((l) => LEAD_OPEN.includes(up(l.status))).length,
    quotes: quoteFollowUps(input.quotes, now),
    applications: (input.proposals ?? []).filter((p) => PROPOSAL_IN_FLIGHT.includes(up(p.status))).length,
    returned: returnedApplications(input.proposals),
  };
}

/** Same drill-down as the proposals list: issued -> policy, else the client, else the list. */
export const proposalHref = (p: Proposal) => (p.policy_id ? `/agent/policies/${p.policy_id}` : p.customer_id ? `/agent/clients/${p.customer_id}` : "/agent/proposals");

export type Activity = { id: string; kind: "lead" | "quote" | "proposal" | "claim"; title: string; status: string; at: string; href: string };

/** Newest events across the agent's lists (default five). Rows without a date are skipped. */
export function recentActivity(
  input: { leads?: Lead[] | null; quotes?: Quote[] | null; proposals?: Proposal[] | null; claims?: Claim[] | null },
  limit = 5,
): Activity[] {
  const rows: Activity[] = [];
  const push = (a: Omit<Activity, "at">, at: string | null | undefined) => {
    if (at && !Number.isNaN(Date.parse(at))) rows.push({ ...a, at });
  };
  for (const l of input.leads ?? []) push({ id: `lead:${l.id}`, kind: "lead", title: l.full_name, status: up(l.status), href: `/agent/leads/${l.id}` }, l.updated_at ?? l.created_at);
  for (const q of input.quotes ?? []) push({ id: `quote:${q.id}`, kind: "quote", title: q.customer_name, status: up(q.status), href: `/agent/quotes/${q.id}` }, q.created_at);
  for (const p of input.proposals ?? [])
    push({ id: `proposal:${p.id}`, kind: "proposal", title: `${p.customer_name} · ${p.proposal_number}`, status: up(p.status), href: proposalHref(p) }, p.decided_at ?? p.submitted_at ?? p.created_at);
  for (const c of input.claims ?? []) push({ id: `claim:${c.id}`, kind: "claim", title: `${c.customer_name} · ${c.claim_number}`, status: up(c.status), href: `/agent/claims/${c.id}` }, c.submitted_at);
  return rows.sort((a, b) => Date.parse(b.at) - Date.parse(a.at)).slice(0, limit);
}
