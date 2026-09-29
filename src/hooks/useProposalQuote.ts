import { InsuranceApi, type Proposal, type QuoteResult } from "@/api/client";
import { proposalQuoteId } from "@/lib/offerChoice";
import { useLoad } from "@/hooks/useLoad";

/**
 * The quote an application was made from (GET quotes/{id}: quote with its risk
 * facts and lifecycle state, offers with their insurer). GET proposals/{id}
 * carries neither the insurer nor the insured object, so the contract summary
 * reads them here; a deep link without anything in the store still shows them.
 * Optional: null while loading or when the quote cannot be read.
 */
export function useProposalQuote(proposal: Proposal | null | undefined): QuoteResult | null {
  const quoteId = proposalQuoteId(proposal);
  const q = useLoad(() => (quoteId ? InsuranceApi.quote(quoteId).catch(() => null) : Promise.resolve(null)), [quoteId]);
  return q.data?.quote ? { quote: q.data.quote, offers: Array.isArray(q.data.offers) ? q.data.offers : [] } : null;
}
