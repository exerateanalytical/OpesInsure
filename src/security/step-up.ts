import { router } from "expo-router";
import { ApiError } from "@/api/client";

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
