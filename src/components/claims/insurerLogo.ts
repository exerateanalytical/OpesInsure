import { useCallback } from "react";
import { CustomerApi } from "@/api/customer";
import { institutionLogo } from "@/components/InstitutionMark";
import { useLoad } from "@/hooks/useLoad";

type LogoSource = {
  carrier_id?: string | null;
  carrier_logo_url?: string | null;
  logo_url?: string | null;
  logo?: string | null;
  carrier_name?: string | null;
  carrier?: { id?: string; party?: { display_name?: string } } | null;
} | null | undefined;

/**
 * Insurer logo for claim screens: the payload's own logo (carrier_logo_url /
 * logo_url / logo) first, else the public institution directory looked up by
 * carrier id, then by name. Returns null until the insurer has a logo, so
 * InstitutionMark falls back to initials and shows the logo the moment the
 * backend has one.
 */
export function useInsurerLogo() {
  const insurers = useLoad(() => CustomerApi.institutions("insurer"));
  const rows = insurers.data;
  return useCallback(
    (...input: unknown[]): string | null => {
      // Claim, policy and wallet payloads all qualify; missing fields are skipped.
      const sources = input.filter((x): x is object => !!x && typeof x === "object") as LogoSource[];
      for (const src of sources) {
        const own = src?.carrier_logo_url ?? institutionLogo(src);
        if (own) return own;
      }
      for (const src of sources) {
        const carrierId = src?.carrier_id ?? src?.carrier?.id;
        const byId = carrierId ? rows?.find((i) => i.id === carrierId) : undefined;
        if (institutionLogo(byId)) return institutionLogo(byId);
        const name = (src?.carrier_name ?? src?.carrier?.party?.display_name ?? "").toLowerCase();
        const byName = name ? rows?.find((i) => i.name.toLowerCase() === name) : undefined;
        if (institutionLogo(byName)) return institutionLogo(byName);
      }
      return null;
    },
    [rows],
  );
}
