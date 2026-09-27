/**
 * Step-up purpose selection and the "obtain grant -> call -> server asks ->
 * step up -> retry once" loop (mobile audit B5). No imports (node-tested).
 *
 * Deploy-safe: when the server does not know a purpose yet (challenge request
 * rejected), `obtain` reports "unsupported" and the call goes ahead without a
 * grant; the server stays the authority and will ask if it needs one.
 */
export const STEP_UP_PURPOSES = {
  signOutEverywhere: "SIGN_OUT_EVERYWHERE",
  payoutDestination: "PAYOUT_DESTINATION_CHANGE",
  profileSecurity: "PROFILE_SECURITY_CHANGE",
} as const;

const norm = (v: string | null | undefined) => (v ?? "").replace(/\s+/g, "").toLowerCase();

/** PATCH /mobile/agent/profile: only a changed momo number needs a grant. */
export function agentProfileStepUpPurpose(
  before: { momo_phone_e164?: string | null } | null | undefined,
  after: { momo_phone_e164?: string | null },
): string | null {
  return norm(before?.momo_phone_e164) !== norm(after.momo_phone_e164) ? STEP_UP_PURPOSES.payoutDestination : null;
}

/** PATCH /mobile/account/profile: only a changed e-mail needs a grant. */
export function accountProfileStepUpPurpose(beforeEmail: string | null | undefined, afterEmail: string | null | undefined): string | null {
  return norm(beforeEmail) !== norm(afterEmail) ? STEP_UP_PURPOSES.profileSecurity : null;
}

/** The server's "verify first" answer (401 today; 403/428 tolerated). */
export function isStepUpRequired(error: unknown): boolean {
  const e = error as { status?: number; code?: string } | null;
  if (!e || typeof e !== "object") return false;
  if (e.code === "STEP_UP_REQUIRED") return true;
  return e.status === 428;
}

export type StepUpOutcome = "granted" | "cancelled" | "unsupported";
export const STEP_UP_CANCELLED = Symbol("STEP_UP_CANCELLED");

export type StepUpDeps = {
  /** A stored, unexpired grant for this purpose exists. */
  hasGrant: (purpose: string) => Promise<boolean>;
  /** Run the step-up challenge UI and report how it ended. */
  obtain: (purpose: string) => Promise<StepUpOutcome>;
  /** Drop a stored grant the server refused. */
  clear: () => Promise<void>;
};

/**
 * `run(withGrant)` performs the call; `withGrant` says whether a grant for
 * `purpose` should be attached. Retries at most once after the server asks.
 */
export async function runWithStepUp<T>(
  purpose: string | null,
  run: (withGrant: boolean) => Promise<T>,
  deps: StepUpDeps,
): Promise<T | typeof STEP_UP_CANCELLED> {
  let withGrant = false;
  if (purpose) {
    if (await deps.hasGrant(purpose)) withGrant = true;
    else {
      const outcome = await deps.obtain(purpose);
      if (outcome === "cancelled") return STEP_UP_CANCELLED;
      withGrant = outcome === "granted";
    }
  }
  try {
    return await run(withGrant);
  } catch (error) {
    if (!purpose || !isStepUpRequired(error)) throw error;
    await deps.clear();
    const outcome = await deps.obtain(purpose);
    if (outcome === "cancelled") return STEP_UP_CANCELLED;
    if (outcome === "unsupported") throw error;
    return run(true);
  }
}
