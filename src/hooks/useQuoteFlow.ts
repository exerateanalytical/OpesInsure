import { useCallback, useEffect, useState } from "react";
import { router, useNavigation } from "expo-router";
import { ProposalsApi, QuoteOffer } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { acceptedOfferId, editQuotePlan, proposalForOffer } from "@/lib/offerChoice";

/**
 * The quote a customer quote screen shows (offers, comparison, risk edit). The insurance store is
 * in memory only, so after an app restart or a deep link the screen reloads the quote named in its
 * `quoteId` param; a different quote in the store is replaced, never shown instead.
 */
export function useOpenQuote(quoteId?: string | null) {
  const quote = useInsurance((s) => s.quote);
  const offers = useInsurance((s) => s.offers);
  const loadQuote = useInsurance((s) => s.loadQuote);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const mismatch = !!quoteId && quote?.id !== quoteId;

  const reload = useCallback(async () => {
    if (!quoteId) return;
    setLoading(true);
    setError(null);
    try {
      await loadQuote(quoteId);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [quoteId, loadQuote]);

  useEffect(() => {
    if (mismatch) void reload();
  }, [mismatch, reload]);

  return {
    quote: mismatch ? null : quote,
    offers: mismatch ? [] : offers,
    loading: loading || (mismatch && !error),
    error,
    reload,
  };
}

/**
 * Choosing an offer (offer cards and comparison columns): one request at a time, the returned
 * application opened with `replace` (back never lands on a list that still offers "Select"), and
 * the accepted offer's button opens its application instead of creating a second one.
 */
export function useChooseOffer(quoteId: string | null | undefined, offers: QuoteOffer[]) {
  const selectOffer = useInsurance((s) => s.selectOffer);
  const accepted = useInsurance((s) => s.accepted);
  const [choosing, setChoosing] = useState<string | null>(null);
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const acceptedId = acceptedOfferId(offers) ?? (accepted && accepted.quoteId === quoteId ? accepted.offerId : null);

  const choose = async (offer: QuoteOffer) => {
    if (choosing || opening || acceptedId) return;
    setChoosing(offer.id);
    setError(null);
    try {
      const proposal = await selectOffer(offer);
      router.replace({ pathname: "/proposals/[id]", params: { id: proposal.id } });
    } catch (e) {
      setError(e);
    } finally {
      setChoosing(null);
    }
  };

  const openApplication = async () => {
    if (!acceptedId || opening) return;
    const known = accepted && accepted.offerId === acceptedId ? accepted.proposalId : null;
    if (known) return router.replace({ pathname: "/proposals/[id]", params: { id: known } });
    setOpening(true);
    try {
      const id = proposalForOffer((await ProposalsApi.list(1)).items, acceptedId);
      if (id) router.replace({ pathname: "/proposals/[id]", params: { id } });
      else router.push("/proposals");
    } catch {
      router.push("/proposals");
    } finally {
      setOpening(false);
    }
  };

  return { choosing, opening, error, clearError: () => setError(null), acceptedId, choose, openApplication };
}

/**
 * "Edit quote" from the offers or comparison screen: back to the risk form when it is right below,
 * otherwise the offers/compare screens are dismissed and replaced by the risk form prefilled from
 * this quote (so no stale offers screen stays underneath). See editQuotePlan.
 */
export function useEditQuote(quoteId: string | null | undefined) {
  const navigation = useNavigation();
  return () => {
    const state = navigation.getState();
    const names = (state?.routes ?? []).map((r) => r.name);
    const plan = editQuotePlan(names, state?.index ?? names.length - 1);
    if (plan.dismiss > 0) router.dismiss(plan.dismiss);
    if (plan.action === "replace") router.replace(quoteId ? { pathname: "/quote/risk", params: { quoteId } } : "/quote/risk");
  };
}
