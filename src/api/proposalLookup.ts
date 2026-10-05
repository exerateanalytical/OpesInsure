import { apiPage, ProposalsApi, type ProposalSummary } from "./client";
import { proposalForOffer } from "@/lib/offerChoice";

/** Pages searched when the server ignores the filter (older build): 10 × 20 applications. */
const MAX_PAGES = 10;

/**
 * The customer's existing application for an offer ("proposal_exists", or "open my application"):
 * GET /mobile/proposals?quote_offer_id= (server filter), then — only if that finds nothing — every
 * page of the list, so an application older than the first page is still found.
 */
export async function findApplicationForOffer(offerId: string): Promise<string | null> {
  const filtered = await apiPage<ProposalSummary>(`/mobile/proposals?quote_offer_id=${encodeURIComponent(offerId)}`).catch(() => null);
  const direct = proposalForOffer(filtered?.items, offerId);
  if (direct) return direct;
  for (let page = 1; page <= MAX_PAGES; page++) {
    const result = await ProposalsApi.list(page);
    const hit = proposalForOffer(result.items, offerId);
    if (hit) return hit;
    if (!result.info.hasMore || !result.items.length) break;
  }
  return null;
}
