import type { CarrierModule } from "@/lib/carrierAccess";
import type { CopyKey as TranslationKey } from "@/i18n/strings";

/** Entry route and label per carrier module (shared by the carrier home and
 * the specialist workspaces, CAR-014). */
export const CARRIER_MODULE_HREF: Record<CarrierModule, string> = {
  products: "/carrier/products",
  proposals: "/carrier/proposals",
  quote_requests: "/carrier/quote-requests",
  referrals: "/carrier/referrals",
  issuance: "/carrier/issuance",
  policies: "/carrier/policies",
  claims: "/carrier/claims",
  payments: "/carrier/payments",
  partners: "/carrier/partners",
  settlements: "/carrier/settlements",
  bordereaux: "/carrier/bordereaux",
};

export const CARRIER_MODULE_LABEL: Record<CarrierModule, TranslationKey> = {
  products: "caProducts",
  proposals: "caQuotesProposals",
  quote_requests: "cqrTitle",
  referrals: "portalTab_Underwriting",
  issuance: "caIssuance",
  policies: "policies",
  claims: "claims",
  payments: "faqTopicPayments",
  partners: "caDistributionPartners",
  settlements: "caSettlements",
  bordereaux: "caBordereaux",
};
