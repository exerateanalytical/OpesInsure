import { useEffect, useState } from "react";
import { CustomerApi } from "@/api/customer";
import type { Institution } from "@/api/extra";
import { institutionLogo } from "@/components/InstitutionMark";

/**
 * Insurer logos for payloads that carry only a carrier id or name (offers,
 * payments, receipts). One GET /public/institutions?type=insurer per app
 * session, shared by every caller; a failed load is retried on next mount.
 */
let cache: Institution[] | null = null;
let pending: Promise<Institution[]> | null = null;

function loadInsurers(): Promise<Institution[]> {
  if (cache) return Promise.resolve(cache);
  if (!pending)
    pending = CustomerApi.institutions("insurer")
      .then((rows) => (cache = Array.isArray(rows) ? rows : []))
      .catch(() => [] as Institution[])
      .finally(() => {
        pending = null;
      });
  return pending;
}

const norm = (v: unknown) => (typeof v === "string" ? v.trim().toLowerCase() : "");

/** Finds the directory row for a carrier by id, then by (short) name. */
export function findInsurer(rows: Institution[], carrierId?: string | null, name?: string | null): Institution | null {
  if (carrierId) {
    const byId = rows.find((r) => r.id === carrierId);
    if (byId) return byId;
  }
  const n = norm(name);
  if (!n) return null;
  return rows.find((r) => norm(r.name) === n || norm((r as { short_name?: string }).short_name) === n) ?? null;
}

/** Directory of licensed insurers (cached). */
export function useInsurerDirectory(): Institution[] {
  const [rows, setRows] = useState<Institution[]>(cache ?? []);
  useEffect(() => {
    let live = true;
    if (!cache) void loadInsurers().then((r) => live && setRows(r));
    return () => {
      live = false;
    };
  }, []);
  return rows;
}

/**
 * Logo URL for a carrier: the payload's own logo wins; otherwise the
 * directory row's logo_url. Null until a logo exists (InstitutionMark then
 * shows initials).
 */
export function useInsurerLogo(carrierId?: string | null, name?: string | null, payloadLogo?: string | null): string | null {
  const rows = useInsurerDirectory();
  if (payloadLogo) return payloadLogo;
  return institutionLogo(findInsurer(rows, carrierId, name));
}

/** Resolver form for lists: logoFor(carrierId, name, payloadLogo). */
export function useInsurerLogos() {
  const rows = useInsurerDirectory();
  return (carrierId?: string | null, name?: string | null, payloadLogo?: string | null) =>
    payloadLogo || institutionLogo(findInsurer(rows, carrierId, name));
}
