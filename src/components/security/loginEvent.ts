import { useCallback } from "react";
import type { LoginActivity } from "@/api/client";
import { isFailedEvent } from "@/lib/securityActivity";
import { useTranslation } from "@/i18n";

/** Title for a login-activity row (sign-in, failed attempt, or the event's own label). One source for every screen. */
export function useLoginEventLabel() {
  const { t, td } = useTranslation();
  return useCallback(
    (row: LoginActivity) => {
      const method = (row.method ?? "").replaceAll("_", " ").toLowerCase() || "-";
      const event = (row.event_type ?? "").toUpperCase();
      if (event && event !== "LOGIN") return td(`loginEvent_${event}`, event.replaceAll("_", " ").toLowerCase());
      return t(isFailedEvent(row) ? "secActivityFailed" : "secActivitySignIn", { method });
    },
    [t, td],
  );
}
