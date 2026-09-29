/**
 * Customer Home feed logic (app/(customer)/(tabs)/index.tsx): which policies
 * show, the merged "In progress" list (open quotes, active claims, renewals
 * due, applications awaiting payment), where its "See all" goes, and which sections render while data loads,
 * fails or is empty. Pure; node-tested in tests/home-feed.test.mjs.
 */
import { isActiveClaim, normalizeClaimStatus } from "./claimStatus.ts";
import { daysUntil, isRenewalDue } from "./customerLogic.ts";

/** Quotes the customer can still act on (legacy status projection). */
export const OPEN_QUOTE = /^(DRAFT|QUOTING|RATED|OFFERED|REFERRED|PENDING)/;
export const HOME_POLICY_LIMIT = 2;
export const IN_PROGRESS_LIMIT = 5;
/** A renewal this close (days) jumps to the top of the list. */
export const URGENT_RENEWAL_DAYS = 7;

export type FeedQuote = { id: string; status?: string | null; can_resume?: boolean | null; offer_count?: number | null; created_at?: string | null };
export type FeedClaim = { id: string; status?: string | null; created_at?: string | null; incident_at?: string | null };
export type FeedPolicy = { id: string; status?: string | null; coverage_ends_at?: string | null };
/** An application already known to be payable (src/lib/paymentRouting.ts payableApplications). */
export type FeedApplication = { id: string; status?: string | null; updated_at?: string | null; created_at?: string | null };

export type InProgressItem<Q extends FeedQuote = FeedQuote, C extends FeedClaim = FeedClaim, P extends FeedPolicy = FeedPolicy, A extends FeedApplication = FeedApplication> =
  | { kind: "quote"; key: string; at: number; urgent: boolean; quote: Q }
  | { kind: "claim"; key: string; at: number; urgent: boolean; claim: C }
  | { kind: "renewal"; key: string; at: number; urgent: boolean; days: number; policy: P }
  | { kind: "application"; key: string; at: number; urgent: boolean; application: A };

export type InProgressKinds = { quote: number; claim: number; renewal: number; application?: number };

export const isOpenQuote = (q: FeedQuote) => !!q.can_resume || OPEN_QUOTE.test(String(q.status ?? "").toUpperCase());

/** Policies shown under "Your policies": ACTIVE or EXPIRING, at most two. */
export function homePolicies<P extends FeedPolicy>(policies: P[], limit = HOME_POLICY_LIMIT): P[] {
  return policies.filter((p) => ["ACTIVE", "EXPIRING"].includes(String(p.status ?? "").toUpperCase())).slice(0, limit);
}

const time = (iso: string | null | undefined) => {
  const at = iso ? Date.parse(iso) : NaN;
  return Number.isNaN(at) ? 0 : at;
};

/**
 * Merged "In progress" list, most urgent first, then newest first:
 *  - urgent = renewal due within 7 days, claim waiting on the customer's
 *    evidence, or quote with offers ready;
 *  - a renewal counts as happening now (a sooner due date sorts first);
 *  - quotes by created_at, claims by reported date (created_at, else incident);
 *  - an application awaiting payment ("Pay now") is always urgent.
 * `total` / `kinds` count everything before the limit (for "See all");
 * `kinds.application` is only present when applications were passed.
 */
export function inProgressItems<Q extends FeedQuote, C extends FeedClaim, P extends FeedPolicy, A extends FeedApplication = FeedApplication>(
  input: { quotes?: Q[] | null; claims?: C[] | null; policies?: P[] | null; applications?: A[] | null },
  now: Date = new Date(),
  limit = IN_PROGRESS_LIMIT,
): { items: InProgressItem<Q, C, P, A>[]; total: number; kinds: InProgressKinds } {
  const all: InProgressItem<Q, C, P, A>[] = [];
  for (const application of input.applications ?? [])
    all.push({ kind: "application", key: `application:${application.id}`, at: time(application.updated_at) || time(application.created_at), urgent: true, application });
  for (const quote of input.quotes ?? []) {
    if (!isOpenQuote(quote)) continue;
    const offered = String(quote.status ?? "").toUpperCase().startsWith("OFFERED") || (quote.offer_count ?? 0) > 0;
    all.push({ kind: "quote", key: `quote:${quote.id}`, at: time(quote.created_at), urgent: offered, quote });
  }
  for (const claim of input.claims ?? []) {
    if (!isActiveClaim(claim.status)) continue;
    const urgent = normalizeClaimStatus(claim.status) === "EVIDENCE_PENDING";
    all.push({ kind: "claim", key: `claim:${claim.id}`, at: time(claim.created_at) || time(claim.incident_at), urgent, claim });
  }
  for (const policy of input.policies ?? []) {
    if (!isRenewalDue(policy, now)) continue;
    const days = daysUntil(policy.coverage_ends_at, now) ?? 0;
    all.push({ kind: "renewal", key: `renewal:${policy.id}`, at: now.getTime() - days, urgent: days <= URGENT_RENEWAL_DAYS, days, policy });
  }
  all.sort((a, b) => Number(b.urgent) - Number(a.urgent) || b.at - a.at || a.key.localeCompare(b.key));
  const kinds: InProgressKinds = { quote: 0, claim: 0, renewal: 0, ...(input.applications ? { application: 0 } : {}) };
  for (const item of all) kinds[item.kind] = (kinds[item.kind] ?? 0) + 1;
  return { items: all.slice(0, limit), total: all.length, kinds };
}

export type SeeAllTarget = "/(customer)/(tabs)/policies" | "/(customer)/(tabs)/claims" | "/quotes" | "/proposals";

/**
 * "See all" of "In progress": the Policies tab when a renewal is due (that is
 * where renewals live), else the Claims tab when a claim is open, else
 * /proposals when only applications awaiting payment are listed, else /quotes.
 */
export function inProgressSeeAll(kinds: InProgressKinds): SeeAllTarget {
  if (kinds.renewal > 0) return "/(customer)/(tabs)/policies";
  if (kinds.claim > 0) return "/(customer)/(tabs)/claims";
  if ((kinds.application ?? 0) > 0 && kinds.quote === 0) return "/proposals";
  return "/quotes";
}

/** What a Home section shows: skeleton rows, its list, one retry row, or nothing. */
export type SectionState = "skeleton" | "list" | "error" | "hidden";

export type HomeLayoutInput = {
  /** First load of each source finished (success or failure). */
  policiesReady: boolean;
  quotesReady: boolean;
  claimsReady: boolean;
  policiesError: boolean;
  /** Quotes or claims failed (policies feed renewals, counted via policiesError). */
  activityError: boolean;
  policyCount: number;
  inProgressCount: number;
};

/**
 * Section states for Home. A brand-new customer (everything loaded, nothing
 * failed, no active policy, nothing in progress) gets ONE "Get your first
 * quote" card instead of the two sections.
 */
export function homeLayout(i: HomeLayoutInput): { firstQuote: boolean; policies: SectionState; inProgress: SectionState } {
  const activityReady = i.policiesReady && i.quotesReady && i.claimsReady;
  const activityFailed = i.activityError || i.policiesError;
  const firstQuote = activityReady && !i.policiesError && !i.activityError && i.policyCount === 0 && i.inProgressCount === 0;
  const policies: SectionState = !i.policiesReady ? "skeleton" : i.policyCount > 0 ? "list" : i.policiesError ? "error" : "hidden";
  const inProgress: SectionState =
    i.inProgressCount > 0 && activityReady ? "list" : !activityReady ? "skeleton" : activityFailed ? "error" : "hidden";
  return { firstQuote, policies: firstQuote ? "hidden" : policies, inProgress: firstQuote ? "hidden" : inProgress };
}
