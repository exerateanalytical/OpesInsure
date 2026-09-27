import { router } from "expo-router";
import { ApiError, StepUpVault } from "@/api/client";
import { runWithStepUp, STEP_UP_CANCELLED, type StepUpDeps, type StepUpOutcome } from "@/lib/stepUpFlow";

export function handleStepUpRequired(
  error: unknown,
  purpose: string,
  returnTo: string,
) {
  if (!(error instanceof ApiError) || error.code !== "STEP_UP_REQUIRED")
    return false;
  router.push({
    pathname: "/security/step-up" as never,
    params: { purpose, returnTo },
  });
  return true;
}

/** One in-flight step-up the challenge screen settles (granted / cancelled / unsupported). */
let pending: { purpose: string; resolve: (o: StepUpOutcome) => void } | null = null;

export const hasPendingStepUp = (purpose: string) => pending?.purpose === purpose;

export function settleStepUp(outcome: StepUpOutcome) {
  const p = pending;
  pending = null;
  p?.resolve(outcome);
}

/** Opens the step-up screen and resolves when it is verified, left, or the purpose is unknown to the server. */
export function requestStepUp(purpose: string): Promise<StepUpOutcome> {
  settleStepUp("cancelled");
  return new Promise<StepUpOutcome>((resolve) => {
    pending = { purpose, resolve };
    router.push({ pathname: "/security/step-up" as never, params: { purpose, mode: "await" } });
  });
}

const deps: StepUpDeps = {
  hasGrant: async (purpose) => !!(await StepUpVault.valid(purpose)),
  obtain: requestStepUp,
  clear: () => StepUpVault.clear(),
};

/** runWithStepUp bound to the real vault + step-up screen. */
export const withStepUp = <T,>(purpose: string | null, run: (withGrant: boolean) => Promise<T>) =>
  runWithStepUp(purpose, run, deps);
export { STEP_UP_CANCELLED };
