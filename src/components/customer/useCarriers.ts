import { useEffect, useState } from "react";
import { CustomerApi } from "@/api/customer";
import type { Institution } from "@/api/extra";
import { institutionLogo } from "@/components/InstitutionMark";

/**
 * Insurer directory (GET /public/institutions?type=insurer) keyed by carrier
 * id, shared by every card that shows a provider (policies, claims, Home).
 * One request per app session; a failed load is retried on the next mount.
 */
let cache: Promise<Institution[]> | null = null;
const load = () => {
  if (!cache) cache = CustomerApi.institutions("insurer").catch((e) => {
    cache = null;
    throw e;
  });
  return cache;
};

export function useCarriers() {
  const [rows, setRows] = useState<Institution[]>([]);
  useEffect(() => {
    let alive = true;
    load()
      .then((r) => alive && setRows(r))
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, []);
  return rows;
}

export type CarrierMark = { name: string | null; logoUrl: string | null; initials: string };

const initialsOf = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase() || "•";

/** Name, logo and initials for a carrier: payload fields first, then the directory row by id. */
export function carrierMark(
  rows: Institution[],
  carrierId: string | null | undefined,
  payload: { name?: string | null; logoUrl?: string | null },
): CarrierMark {
  const row = carrierId ? rows.find((r) => r.id === carrierId) : undefined;
  const name = row?.short_name ?? payload.name ?? row?.name ?? null;
  return {
    name,
    logoUrl: payload.logoUrl ?? institutionLogo(row),
    initials: row?.initials ?? (name ? initialsOf(name) : "•"),
  };
}
