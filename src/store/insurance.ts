import { create } from "zustand";
import AsyncStorage from "@react-native-async-storage/async-storage";
import {
  ApiError,
  InsuranceApi,
  Payment,
  Policy,
  Proposal,
  PurchaseStatus,
  Quote,
  QuoteOffer,
  QuoteResult,
  ProposalsApi,
  QuotesApi,
  TokenVault,
} from "@/api/client";
import { QuoteWorkflowApi } from "@/api/workflow";
import * as Crypto from "expo-crypto";
import { copyText, forgetProposalSlots, paymentAttemptSlot, paymentIdempotencyKey, rememberAttemptKey } from "@/lib/purchase";
import { findApplicationForOffer } from "@/api/proposalLookup";
import { SecureJson } from "@/security/secureJson";

type Network = "mtn_momo" | "orange_money";
export type InsuredPerson =
  | { mode: "self" }
  | { mode: "other"; full_name: string; date_of_birth: string; relationship: string };

const RECENT_PROPOSALS = "opesinsure.recent_proposals";

/** App language for the store's fallback messages; wired by src/i18n (no import cycle through the session store). */
let languageOf: () => string = () => "en";
export const setInsuranceLanguage = (fn: () => string) => {
  languageOf = fn;
};
const say = (key: string) => copyText(languageOf(), key);
const FAILED = ["FAILED", "EXPIRED", "CANCELLED"];

/** Proposal ids this device created — the fallback for "My applications". */
export const RecentProposals = {
  async list(): Promise<string[]> {
    try {
      const raw = await AsyncStorage.getItem(RECENT_PROPOSALS);
      const ids = raw ? (JSON.parse(raw) as unknown) : [];
      return Array.isArray(ids) ? ids.filter((x): x is string => typeof x === "string") : [];
    } catch {
      return [];
    }
  },
  async add(id: string) {
    const ids = (await RecentProposals.list()).filter((x) => x !== id);
    try {
      await AsyncStorage.setItem(RECENT_PROPOSALS, JSON.stringify([id, ...ids].slice(0, 25)));
    } catch {
      // Best effort only; the server remains the source of truth.
    }
  },  /** Signed-out devices keep no trace of which proposals were opened here. */
  clear: () => AsyncStorage.removeItem(RECENT_PROPOSALS).catch(() => undefined),
};

/** Random, persisted Idempotency-Key per payment attempt (no phone in it). */
const PAYMENT_KEYS = "opesinsure.payment_attempt_keys";
export const PaymentAttemptKeys = {
  async forSlot(slot: string) {
    const map = await SecureJson.read<Record<string, string>>(PAYMENT_KEYS, {});
    const existing = map[slot];
    if (existing) return paymentIdempotencyKey(existing);
    const uuid = Crypto.randomUUID();
    await SecureJson.write(PAYMENT_KEYS, rememberAttemptKey(map, slot, uuid)).catch(() => undefined);
    return paymentIdempotencyKey(uuid);
  },
  /** Once a payment is final its keys are no longer needed (OPS-07). */
  async forgetProposal(proposalId: string) {
    const map = await SecureJson.read<Record<string, string>>(PAYMENT_KEYS, {});
    const next = forgetProposalSlots(map, proposalId);
    if (Object.keys(next).length === Object.keys(map).length) return;
    if (Object.keys(next).length === 0) await SecureJson.remove(PAYMENT_KEYS);
    else await SecureJson.write(PAYMENT_KEYS, next);
  },
  clear: () => SecureJson.remove(PAYMENT_KEYS),
};

type State = {
  product: string | null;
  riskFacts: Record<string, unknown>;
  riskAssetId: string | null;
  insured: InsuredPerson;
  quote: Quote | null;
  offers: QuoteOffer[];
  selectedOffer: QuoteOffer | null;
  proposal: Proposal | null;
  payment: Payment | null;
  purchase: PurchaseStatus | null;
  policy: Policy | null;
  /** Payment attempt number per proposal (drives the idempotency key). */
  paymentAttempts: Record<string, number>;
  /** Offer accepted on this device for the open quote and the application it opened. */
  accepted: { quoteId: string; offerId: string; proposalId: string } | null;
  busy: boolean;
  error: string | null;
  setProduct: (v: string) => void;
  setRiskFacts: (v: Record<string, unknown>) => void;
  setRiskAsset: (id: string | null) => void;
  setInsured: (v: InsuredPerson) => void;
  setQuoteResult: (q: Quote, o: QuoteOffer[]) => void;
  loadQuote: (id: string) => Promise<QuoteResult>;
  /** Creates and rates a quote; with `amendQuoteId` the existing quote is amended (PATCH) and re-rated instead. */
  submitQuote: (customerId: string, coords?: { latitude?: number; longitude?: number }, opts?: { amendQuoteId?: string | null }) => Promise<QuoteResult>;
  rerateQuote: (id: string) => Promise<QuoteResult>;
  /** Accepts the offer and returns its application (created, or the one already open for it). */
  selectOffer: (v: QuoteOffer) => Promise<Proposal>;
  setProposal: (v: Proposal) => void;
  loadProposal: (id: string) => Promise<Proposal>;
  requestPayment: (v: Network, p: string) => Promise<void>;
  /** The payment of proposalId (when given): the store's, the one saved on this device, else the latest from the purchase status. */
  recoverPayment: (proposalId?: string | null) => Promise<Payment | null>;
  refreshPayment: () => Promise<Payment>;
  refreshPurchase: (proposalId?: string | null) => Promise<PurchaseStatus | null>;
  setPolicy: (v: Policy) => void;
  clear: () => void;
  clearError: () => void;
};

const initial = {
  product: null,
  riskFacts: {},
  riskAssetId: null,
  insured: { mode: "self" } as InsuredPerson,
  quote: null,
  offers: [],
  selectedOffer: null,
  proposal: null,
  payment: null,
  purchase: null,
  policy: null,
  paymentAttempts: {},
  accepted: null,
  busy: false,
  error: null,
};

const message = (e: unknown, fallbackKey: string) => (e instanceof Error && e.message ? e.message : say(fallbackKey));

/** Purchase state that belongs to the previously open quote; dropped when another quote is opened. */
const QUOTE_SCOPED = { selectedOffer: null, proposal: null, payment: null, purchase: null, accepted: null } as const;

/** ApiError worth retrying as a new quote (amend refused: not owner-scoped, gone, wrong state), not a risk-facts validation error. */
const amendRefused = (e: unknown) =>
  e instanceof ApiError &&
  (e.status === 403 || e.status === 404 || e.status === 409 || (e.status === 422 && !Object.keys(e.fields ?? {}).some((k) => k.startsWith("risk_facts"))));

/** /mobile/quotes/{id} returns {quote, offers, summary?}; /quotes/{id} returns {quote, offers}. */
function asResult(x: Partial<QuoteResult> | null | undefined): QuoteResult | null {
  if (!x?.quote) return null;
  return { quote: x.quote, offers: Array.isArray(x.offers) ? x.offers : [] };
}

export const useInsurance = create<State>((set, get) => ({
  ...initial,
  setProduct: (product) => set({ ...initial, paymentAttempts: get().paymentAttempts, product }),
  setRiskFacts: (riskFacts) => set({ riskFacts }),
  setRiskAsset: (riskAssetId) => set({ riskAssetId }),
  setInsured: (insured) => set({ insured }),
  setQuoteResult: (quote, offers) =>
    set({ ...(get().quote?.id === quote.id ? {} : QUOTE_SCOPED), quote, offers, product: quote.line_code?.toLowerCase() ?? get().product }),

  async loadQuote(id) {
    set({ busy: true, error: null });
    try {
      let result = asResult(await QuotesApi.show(id).catch(() => null));
      if (!result) result = asResult(await InsuranceApi.quote(id));
      if (!result) throw new Error(say("insQuoteLoadFailed"));
      const scoped = get().quote?.id === result.quote.id ? {} : QUOTE_SCOPED;
      set({ ...scoped, quote: result.quote, offers: result.offers, product: result.quote.line_code?.toLowerCase() ?? null, busy: false });
      return result;
    } catch (e) {
      set({ busy: false, error: message(e, "insQuoteLoadFailed") });
      throw e;
    }
  },

  async submitQuote(customerId, coords, opts) {
    set({ busy: true, error: null });
    try {
      const line_code = String(get().product ?? "").toUpperCase();
      if (!line_code) throw new Error(say("insChooseProduct"));
      const insured = get().insured;
      const risk_facts = {
        ...get().riskFacts,
        insured_person:
          insured.mode === "self"
            ? { relationship: "SELF" }
            : { relationship: insured.relationship, full_name: insured.full_name, date_of_birth: insured.date_of_birth },
      };
      const point = coords?.latitude != null && coords?.longitude != null ? { latitude: coords.latitude, longitude: coords.longitude } : {};
      // Edit quote: amend the same quote (its offers are superseded) and re-rate, so no duplicate quote is left behind.
      if (opts?.amendQuoteId) {
        try {
          await QuoteWorkflowApi.amend(opts.amendQuoteId, risk_facts, point);
          const result = await InsuranceApi.rateQuote(opts.amendQuoteId);
          set({ ...QUOTE_SCOPED, quote: result.quote, offers: result.offers, busy: false });
          return result;
        } catch (e) {
          if (!amendRefused(e)) throw e;
        }
      }
      const quote = await InsuranceApi.createQuote({
        customer_id: customerId,
        line_code,
        channel: "B2C",
        risk_facts,
        ...(get().riskAssetId ? { risk_asset_id: get().riskAssetId! } : {}),
        ...point,
      });
      const result = await InsuranceApi.rateQuote(quote.id);
      set({ ...QUOTE_SCOPED, quote: result.quote, offers: result.offers, busy: false });
      return result;
    } catch (e) {
      set({ busy: false, error: message(e, "insQuoteUnavailable") });
      throw e;
    }
  },

  async rerateQuote(id) {
    set({ busy: true, error: null });
    try {
      const result = await InsuranceApi.rateQuote(id);
      set({ quote: result.quote, offers: result.offers, busy: false });
      return result;
    } catch (e) {
      set({ busy: false, error: message(e, "insRerateFailed") });
      throw e;
    }
  },

  /**
   * Accept the offer, then open (or reuse) the proposal for it. Returns that proposal so callers
   * never read a stale one from the store. A second call while one runs is refused (callers keep
   * their own busy state on the buttons); a repeat for the offer already accepted here returns the
   * same application.
   */
  async selectOffer(selectedOffer) {
    const quote = get().quote;
    if (!quote || (selectedOffer.quote_id && selectedOffer.quote_id !== quote.id)) throw new Error(say("insQuoteMissing"));
    const done = get().accepted;
    const current = get().proposal;
    if (done && done.offerId === selectedOffer.id && current?.id === done.proposalId) return current;
    if (get().busy) throw new Error(say("insBusy"));
    set({ busy: true, error: null });
    try {
      if (String(selectedOffer.status ?? "").toUpperCase() !== "ACCEPTED") await InsuranceApi.acceptOffer(quote.id, selectedOffer.id);
      let proposal: Proposal;
      try {
        proposal = await InsuranceApi.createProposal({ quote_offer_id: selectedOffer.id, party_id: quote.party_id });
      } catch (e) {
        // 422 quote_offer_id "proposal_exists": the application was opened before (earlier attempt, another device).
        if (!(e instanceof ApiError && e.status === 422 && "quote_offer_id" in (e.fields ?? {}))) throw e;
        const existing = await findApplicationForOffer(selectedOffer.id).catch(() => null);
        if (!existing) throw e;
        proposal = await InsuranceApi.proposal(existing);
      }
      await RecentProposals.add(proposal.id);
      const accepted = { ...selectedOffer, status: "ACCEPTED" };
      set({
        selectedOffer: accepted,
        proposal,
        payment: null,
        purchase: null,
        accepted: { quoteId: quote.id, offerId: selectedOffer.id, proposalId: proposal.id },
        // Mirror the server: the quote is accepted and this offer is the chosen one.
        quote: { ...quote, status: "ACCEPTED" },
        offers: get().offers.map((o) => (o.id === selectedOffer.id ? accepted : o)),
        busy: false,
      });
      return proposal;
    } catch (e) {
      set({ busy: false, error: message(e, "insSelectFailed") });
      throw e;
    }
  },

  setProposal: (proposal) => {
    void RecentProposals.add(proposal.id);
    set({ proposal });
  },

  async loadProposal(id) {
    let proposal = await InsuranceApi.proposal(id);
    // GET proposals/{id} does not carry the revised terms: the mobile projection does (counter_offer).
    if (String(proposal.status).toUpperCase() === "COUNTEROFFERED" && !proposal.counter_offer) {
      const mobile = await ProposalsApi.mobileShow(id).catch(() => null);
      if (mobile?.counter_offer) proposal = { ...proposal, counter_offer: mobile.counter_offer };
    }
    set({ proposal });
    return proposal;
  },

  /**
   * Stable idempotency key per proposal + attempt + payer: a double tap or a
   * timeout-then-retry returns the SAME payment. A new attempt number is used
   * only when the server hands back an already-failed payment.
   */
  async requestPayment(provider, payer_phone_e164) {
    const proposal = get().proposal;
    if (!proposal) throw new Error(say("insProposalNotReady"));
    if (get().busy) return;
    set({ busy: true, error: null });
    try {
      let attempt = get().paymentAttempts[proposal.id] ?? 1;
      let created: Payment | null = null;
      for (let tries = 0; tries < 3; tries++) {
        const key = await PaymentAttemptKeys.forSlot(paymentAttemptSlot(proposal.id, attempt, provider, payer_phone_e164));
        created = await InsuranceApi.createPayment({ proposal_id: proposal.id, provider, payer_phone_e164, idempotency_key: key });
        if (!FAILED.includes(created.status)) break;
        attempt += 1;
      }
      set({ paymentAttempts: { ...get().paymentAttempts, [proposal.id]: attempt } });
      if (!created) throw new Error(say("insPaymentFailed"));
      await TokenVault.setPendingPayment(created.id);
      let payment = created;
      // POST /payments answers PENDING_CUSTOMER (not CREATED): the operator is only prompted by initiate, so
      // initiate whenever nothing has been sent to the operator yet (no provider_reference).
      if (created.status === "CREATED" || (created.status === "PENDING_CUSTOMER" && !created.provider_reference)) {
        try {
          const initKey = await PaymentAttemptKeys.forSlot(paymentAttemptSlot(proposal.id, attempt, provider, payer_phone_e164));
          payment = await InsuranceApi.initiatePayment(created.id, `${initKey}:init`);
        } catch (e) {
          // e.g. 422 errors.provider "not configured": nothing was sent to the
          // operator, so there is nothing to recover or poll.
          if (e instanceof ApiError && e.status >= 400 && e.status < 500) await TokenVault.clearPendingPayment();
          throw e;
        }
      }
      set({ payment, purchase: null, busy: false });
    } catch (e) {
      set({ busy: false, error: message(e, "insPaymentFailed") });
      throw e;
    }
  },

  async recoverPayment(proposalId) {
    const current = get().payment;
    if (current && (!proposalId || current.proposal_id === proposalId)) return get().refreshPayment();
    // Saved on this device (app restarted mid-payment); ignored when it belongs to another application.
    const saved = await TokenVault.pendingPayment();
    if (saved) {
      const payment = await InsuranceApi.payment(saved).catch((e) => {
        if (e instanceof ApiError && e.status === 404) return null;
        throw e;
      });
      if (payment && (!proposalId || payment.proposal_id === proposalId)) {
        set({ payment });
        return payment;
      }
    }
    // Deep link / notification after a restart: the server knows this application's latest payment.
    if (!proposalId) return null;
    const purchase = await get().refreshPurchase(proposalId);
    if (!purchase?.payment?.id) return null;
    const payment = await InsuranceApi.payment(purchase.payment.id);
    set({ payment });
    return payment;
  },

  async refreshPayment() {
    const current = get().payment;
    if (!current) throw new Error(say("insPaymentMissing"));
    const payment = await InsuranceApi.payment(current.id);
    set({ payment });
    if (FAILED.includes(payment.status)) {
      await TokenVault.clearPendingPayment();
      const attempts = get().paymentAttempts;
      set({ paymentAttempts: { ...attempts, [payment.proposal_id]: (attempts[payment.proposal_id] ?? 1) + 1 } });
    }
    return payment;
  },

  /**
   * /mobile/purchases/{proposal}/status is the authoritative view of the
   * provider-confirmed payment and issuance. 404 means the proposal is not
   * linked to this account.
   */
  async refreshPurchase(forProposal) {
    const proposalId = forProposal ?? get().proposal?.id ?? get().payment?.proposal_id;
    if (!proposalId) return null;
    try {
      const purchase = await InsuranceApi.purchaseStatus(proposalId);
      set({ purchase });
      // Keys are kept until the policy exists: a retry while issuance is pending must replay the SAME
      // payment, never mint a new one (the server also refuses a second payment: 409 PAYMENT_ALREADY_MADE).
      if (purchase?.status === "POLICY_ISSUED")
        await PaymentAttemptKeys.forgetProposal(proposalId).catch(() => undefined);
      return purchase;
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) return null;
      throw e;
    }
  },

  setPolicy: (policy) => set({ policy }),
  clear: () => set(initial),
  clearError: () => set({ error: null }),
}));
