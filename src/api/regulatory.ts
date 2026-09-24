import { api } from "./client";
import type { RegulatoryTerm, TermLanguage } from "@/lib/regulatoryTerms";

/**
 * Public CIMA terminology (GET /api/v1/public/regulatory/terms). Cached per
 * locale for the session; a failure resolves to [] so screens fall back to
 * their built-in labels and never break.
 */
const cache = new Map<string, Promise<RegulatoryTerm[]>>();

export const RegulatoryApi = {
  terms: (locale: TermLanguage, category?: string): Promise<RegulatoryTerm[]> => {
    const key = `${locale}:${category ?? ""}`;
    let hit = cache.get(key);
    if (!hit) {
      const query = `regime=CIMA&locale=${locale}${category ? `&category=${encodeURIComponent(category)}` : ""}`;
      hit = api<RegulatoryTerm[]>(`/public/regulatory/terms?${query}`, { anonymous: true }).catch(() => {
        cache.delete(key);
        return [] as RegulatoryTerm[];
      });
      cache.set(key, hit);
    }
    return hit;
  },
};
