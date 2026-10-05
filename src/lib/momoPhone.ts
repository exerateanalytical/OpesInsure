/**
 * Mobile Money payer number (checkout). Customers type it the local way (6XX XX XX XX), with the country
 * code (237…, +237…, 00237…) or with spaces/dashes; the payment is always requested with +237XXXXXXXXX.
 * Pure: node-tested (tests/customer-account-gaps.test.mjs).
 */

/** E.164 (+237 + 9 digits) when the input is a Cameroon number, else the trimmed input unchanged. */
export function normalizeMomoPhone(input: string | null | undefined): string {
  const raw = String(input ?? "").trim();
  const digits = raw.replace(/[\s().-]/g, "");
  if (/^[26]\d{8}$/.test(digits)) return `+237${digits}`;
  if (/^237[26]\d{8}$/.test(digits)) return `+${digits}`;
  if (/^00237[26]\d{8}$/.test(digits)) return `+${digits.slice(2)}`;
  if (/^\+237[26]\d{8}$/.test(digits)) return digits;
  return raw;
}

/** A Cameroon number Mobile Money can charge (mobile 6XXXXXXXX; 2XXXXXXXX kept as before). */
export const isMomoPhone = (input: string | null | undefined) => /^\+237[26]\d{8}$/.test(normalizeMomoPhone(input));
