export type PartnerLook = "agent" | "broker" | "carrier";

/** Which partner portal a pathname belongs to, or null for the customer app (node-tested). */
export function partnerLookFor(pathname: string | null | undefined): PartnerLook | null {
  const m = /^\/(agent|broker|carrier)(\/|$)/.exec(pathname ?? "");
  return m ? (m[1] as PartnerLook) : null;
}
