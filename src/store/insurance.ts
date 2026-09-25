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
  QuotesApi,
  TokenVault,
} from "@/api/client";
import * as Crypto from "expo-crypto";
import { paymentAttemptSlot, paymentIdempotencyKey, rememberAttemptKey } from "@/lib/purchase";
import { SecureJson } from "@/security/secureJson";

type Network = "mtn_momo" | "orange_money";
export type InsuredPerson =
  | { mode: "self" }
  | { mode: "other"; full_name: string; date_of_birth: string; relationship: string };

const RECENT_PROPOSALS = "opesinsure.recent_proposals";
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
  },
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
  busy: boolean;
  error: string | null;
  setProduct: (v: string) => void;
  setRiskFacts: (v: Record<string, unknown>) => void;
  setRiskAsset: (id: string | null) => void;
  setInsured: (v: InsuredPerson) => void;
  setQuoteResult: (q: Quote, o: QuoteOffer[]) => void;
  loadQuote: (id: string) => Promise<QuoteResult>;
  submitQuote: (customerId: string) => Promise<QuoteResult>;
  rerateQuote: (id: string) => Promise<QuoteResult>;
  selectOffer: (v: QuoteOffer) => Promise<void>;
  setProposal: (v: Proposal) => void;
  loadProposal: (id: string) => Promise<Proposal>;
  requestPayment: (v: Network, p: string) => Promise<void>;
  recoverPayment: () => Promise<Payment | null>;
  refreshPayment: () => Promise<Payment>;
  refreshPurchase: () => Promise<PurchaseStatus | null>;
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
  busy: false,
  error: null,
};

const message = (e: unknown, fallback: string) => (e instanceof Error && e.message ? e.message : fallback);

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
  setQuoteResult: (quote, offers) => set({ quote, offers, product: quote.line_code?.toLowerCase() ?? get().product }),

  async loadQuote(id) {
    set({ busy: true, error: null });
    try {
      let result = asResult(await QuotesApi.show(id).catch(() => null));
      if (!result) result = asResult(await InsuranceApi.quote(id));
      if (!result) throw new Error("Quote could not be loaded.");
      set({ quote: result.quote, offers: result.offers, product: result.quote.line_code?.toLowerCase() ?? null, busy: false });
      return result;
    } catch (e) {
      set({ busy: false, error: message(e, "Quote could not be loaded.") });
      throw e;
    }
  },

  async submitQuote(customerId) {
    set({ busy: true, error: null });
    try {
      const line_code = String(get().product ?? "").toUpperCase();
      if (!line_code) throw new Error("Choose an insurance product.");
      const insured = get().insured;
      const risk_facts = {
        ...get().riskFacts,
        insured_person:
          insured.mode === "self"
            ? { relationship: "SELF" }
            : { relationship: insured.relationship, full_name: insured.full_name, date_of_birth: insured.date_of_birth },
      };
      const quote = await InsuranceApi.createQuote({
        customer_id: customerId,
        line_code,
        channel: "B2C",
        risk_facts,
        ...(get().riskAssetId ? { risk_asset_id: get().riskAssetId! } : {}),
      });
      const result = await InsuranceApi.rateQuote(quote.id);
      set({ quote: result.quote, offers: result.offers, busy: false });
      return result;
    } catch (e) {
      set({ busy: false, error: message(e, "Quote unavailable.") });
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
      set({ busy: false, error: message(e, "The quote could not be re-rated.") });
      throw e;
    }
  },

  /** Accept the offer, then open (or reuse) the proposal for it. */
  async selectOffer(selectedOffer) {
    const quote = get().quote;
    if (!quote) throw new Error("Quote context is missing.");
    if (get().busy) return;
    set({ busy: true, error: null });
    try {
      await InsuranceApi.acceptOffer(quote.id, selectedOffer.id);
      const proposal = await InsuranceApi.createProposal({ quote_offer_id: selectedOffer.id, party_id: quote.party_id });
      await RecentProposals.add(proposal.id);
      set({ selectedOffer, proposal, payment: null, purchase: null, busy: false });
    } catch (e) {
      set({ busy: false, error: message(e, "Offer could not be selected.") });
      throw e;
    }
  },

  setProposal: (proposal) => {
    void RecentProposals.add(proposal.id);
    set({ proposal });
  },

  async loadProposal(id) {
    const proposal = await InsuranceApi.proposal(id);
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
    if (!proposal) throw new Error("Proposal is not ready for payment.");
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
      if (!created) throw new Error("Payment request failed.");
      await TokenVault.setPendingPayment(created.id);
      let payment = created;
      if (created.status === "CREATED") {
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
      set({ busy: false, error: message(e, "Payment request failed.") });
      throw e;
    }
  },

  async recoverPayment() {
    const id = await TokenVault.pendingPayment();
    if (!id) return null;
    const payment = await InsuranceApi.payment(id);
    set({ payment });
    return payment;
  },

  async refreshPayment() {
    const current = get().payment;
    if (!current) throw new Error("Payment reference is missing.");
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
   * /mobile/purchases/{proposal}/status is the authoritative view (and the
   * only endpoint that settles demo payments). 404 means the proposal is not
   * linked to this account.
   */
  async refreshPurchase() {
    const proposalId = get().proposal?.id ?? get().payment?.proposal_id;
    if (!proposalId) return null;
    try {
      const purchase = await InsuranceApi.purchaseStatus(proposalId);
      set({ purchase });
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
