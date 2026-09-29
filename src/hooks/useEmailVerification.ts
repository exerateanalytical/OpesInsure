import { useState } from "react";
import { AuthApi } from "@/api/client";
import { useSession } from "@/store/session";

/**
 * The account e-mail's verification state and the "send link" action
 * (POST /me/email/verification). Shared by the Profile tab and Personal
 * information so both read the same session user and send the same way.
 * `unverified` is true only when the server reports it (field present and
 * null / flag false); older payloads without the field show nothing.
 */
export function useEmailVerification() {
  const user = useSession((s) => s.bootstrap?.user);
  const unverified = !!user?.email && (user.email_verified_at === null || user.contacts_verified === false);
  const [state, setState] = useState<"idle" | "busy" | "sent" | "error">("idle");
  const send = async () => {
    setState("busy");
    try {
      const r = await AuthApi.requestEmailVerification();
      setState(r.sent ? "sent" : "error");
    } catch {
      setState("error");
    }
  };
  return { unverified, state, send };
}
