import type { NewClaimParty } from "@/api/client";

/**
 * A witness can be added once it has a name; when a phone number is given the
 * customer must also confirm the witness agreed to share it — the backend
 * (MobileClaimPartyService::add) rejects third-party contact details without
 * consent_given=true.
 */
export function canAddWitness(name: string, phone: string, consent: boolean): boolean {
  if (name.trim().length < 3) return false;
  return phone.trim().length === 0 || consent;
}

/** The POST /mobile/claims/{id}/parties body for a witness. */
export function witnessPayload(name: string, phone: string, consent: boolean): NewClaimParty {
  const contact = phone.trim();
  return {
    role: "WITNESS",
    display_name: name.trim(),
    contact_phone: contact || null,
    consent_given: contact ? consent : false,
  };
}
