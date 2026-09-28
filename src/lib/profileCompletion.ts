/**
 * Customer profile completion, computed only from server data (never from local state):
 *  - GET /mobile/account/customer-profile (date of birth, address, city),
 *  - the session user (phone / email verification),
 *  - GET /mobile/kyc/profile (identifiers + the latest KYC submission).
 * "Complete" means every step is done, including an APPROVED, unexpired verification.
 * Pure: node-tested (tests/profile-completion.test.mjs).
 */
import { canSubmitKyc, kycPhase, type KycRequirement } from "./kyc.ts";

export type CompletionStepKey = "personal" | "phone" | "email" | "identifier" | "documents" | "verified";
export type CompletionStepState = "done" | "todo" | "waiting";
export type CompletionStep = { key: CompletionStepKey; state: CompletionStepState; href: string };

export type CompletionInput = {
  user: { phone_e164?: string | null; phone_verified_at?: string | null; email?: string | null; email_verified_at?: string | null; contacts_verified?: boolean } | null | undefined;
  profile: { date_of_birth?: string | null; address_line1?: string | null; city?: string | null } | null | undefined;
  kyc:
    | {
        identifiers?: unknown[] | null;
        submission?: {
          status?: string | null;
          expires_at?: string | null;
          requirements?: KycRequirement[] | null;
          documents?: unknown[] | null;
        } | null;
      }
    | null
    | undefined;
};

export type Completion = {
  steps: CompletionStep[];
  done: number;
  total: number;
  percent: number;
  /** True only when every step is done (verification APPROVED and not expired). */
  complete: boolean;
};

const filled = (v: string | null | undefined) => typeof v === "string" && v.trim().length > 0;

/** null while any source is still unknown: never claim a percentage (or completion) from partial data. */
export function profileCompletion(input: CompletionInput, now = Date.now()): Completion | null {
  const { user, profile, kyc } = input;
  if (!user || !profile || !kyc) return null;

  const sub = kyc.submission ?? null;
  const { phase, editable } = kycPhase(sub?.status, sub?.expires_at, now);
  const restart = phase === "expired" || phase === "rejected";
  const docs = restart ? 0 : (sub?.documents?.length ?? 0);
  const requirements = restart ? [] : (sub?.requirements ?? []);
  // Documents count as done once the server holds them and nothing mandatory is missing,
  // or once the case has moved past the editable stage without being sent back.
  const documentsDone = phase === "approved" || phase === "in_review" || phase === "pending_approval" || (docs > 0 && canSubmitKyc(true, docs, requirements));

  const steps: CompletionStep[] = [
    {
      key: "personal",
      state: filled(profile.date_of_birth) && filled(profile.address_line1) && filled(profile.city) ? "done" : "todo",
      href: "/account/profile",
    },
    { key: "phone", state: filled(user.phone_e164) && (filled(user.phone_verified_at) || user.contacts_verified === true) ? "done" : "todo", href: "/account/profile" },
  ];
  // Email is optional for customers; once one is on the account it must be verified.
  if (filled(user.email)) {
    steps.push({ key: "email", state: filled(user.email_verified_at) || user.contacts_verified === true ? "done" : "todo", href: "/account/profile" });
  }
  steps.push(
    { key: "identifier", state: (kyc.identifiers?.length ?? 0) > 0 ? "done" : "todo", href: "/onboarding/kyc" },
    { key: "documents", state: documentsDone && !(editable && phase === "more_info") ? "done" : "todo", href: "/onboarding/kyc" },
    {
      key: "verified",
      state: phase === "approved" ? "done" : phase === "in_review" || phase === "pending_approval" ? "waiting" : "todo",
      href: "/onboarding/kyc",
    },
  );

  const done = steps.filter((s) => s.state === "done").length;
  return { steps, done, total: steps.length, percent: Math.round((done / steps.length) * 100), complete: done === steps.length };
}
