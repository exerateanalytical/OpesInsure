/**
 * Demo sign-in credentials. GET /public/demo-accounts (demo mode only)
 * answers { otp, password, accounts: [{ label, full_name, phone_e164,
 * role_code }] }: the shared demo password is at the TOP level of data, not
 * per account. Password login is preferred; without a password the OTP pair
 * with the server's demo code (123456 by default) is used.
 */
export type DemoAccountLike = { label: string; full_name?: string; phone_e164: string; role_code?: string; password?: string | null };
export type DemoDirectory = { otp?: string | null; password?: string | null; accounts: DemoAccountLike[] };

export const DEMO_FALLBACK_OTP = "123456";

export type DemoCredential =
  | { kind: "password"; phone: string; password: string }
  | { kind: "otp"; phone: string; otp: string };

export function demoCredential(directory: DemoDirectory, account: DemoAccountLike): DemoCredential {
  const password = (directory.password ?? "").trim() || (account.password ?? "").trim();
  if (password) return { kind: "password", phone: account.phone_e164, password };
  const otp = String(directory.otp ?? "").trim() || DEMO_FALLBACK_OTP;
  return { kind: "otp", phone: account.phone_e164, otp };
}

/** Normalises the server payload; null when demo mode is off or malformed. */
export function normalizeDemoDirectory(raw: unknown): DemoDirectory | null {
  if (!raw || typeof raw !== "object") return null;
  const r = raw as Record<string, unknown>;
  const accounts = Array.isArray(r.accounts)
    ? (r.accounts as unknown[]).filter(
        (a): a is DemoAccountLike =>
          !!a && typeof a === "object" && typeof (a as DemoAccountLike).phone_e164 === "string" && typeof (a as DemoAccountLike).label === "string",
      )
    : [];
  if (!accounts.length) return null;
  return {
    otp: typeof r.otp === "string" || typeof r.otp === "number" ? String(r.otp) : null,
    password: typeof r.password === "string" ? r.password : null,
    accounts,
  };
}

/** Option label for the picker: "Customer — Awa Ndiaye". */
export const demoOptionLabel = (a: DemoAccountLike) =>
  a.full_name && a.full_name !== a.label ? `${a.label} — ${a.full_name}` : a.label;
