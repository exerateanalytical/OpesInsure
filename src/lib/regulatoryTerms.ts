/**
 * Controlled CIMA contract vocabulary for customer screens. Labels come from
 * the backend RegulatoryTerminologyService (translated by semantic code);
 * these fallbacks are used until it answers or when offline. Customers see
 * labels only — never the semantic codes, never CIMA branch codes.
 */
export type RegulatoryTerm = {
  code: string;
  category: string;
  label: string | null;
  alternatives?: string[];
  source_article?: string | null;
};

export type TermLanguage = "en" | "fr";

export const POLICY_HEADER_TERM_CODES = ["POLICY", "POLICYHOLDER", "INSURED", "PREMIUM"] as const;

export type PolicyHeaderLabels = {
  policy: string;
  policyholder: string;
  insured: string;
  totalPremium: string;
};

const FALLBACK: Record<TermLanguage, PolicyHeaderLabels> = {
  fr: { policy: "Police d'assurance", policyholder: "Souscripteur", insured: "Assuré", totalPremium: "Prime totale" },
  en: { policy: "Insurance policy", policyholder: "Policyholder", insured: "Insured", totalPremium: "Total premium" },
};

/** Maps terminology rows to the policy detail header labels. French uses the CIMA terms; English keeps the app's plain wording. */
export function policyHeaderLabels(language: string, terms: RegulatoryTerm[] | null | undefined): PolicyHeaderLabels {
  const lang: TermLanguage = language === "fr" ? "fr" : "en";
  const base = FALLBACK[lang];
  if (lang !== "fr" || !terms?.length) return base;
  const label = (code: string) => terms.find((t) => t.code === code)?.label?.trim() || null;
  const premium = label("PREMIUM");
  return {
    policy: label("POLICY") ?? base.policy,
    policyholder: label("POLICYHOLDER") ?? base.policyholder,
    insured: label("INSURED") ?? base.insured,
    totalPremium: premium ? `${premium} totale` : base.totalPremium,
  };
}
