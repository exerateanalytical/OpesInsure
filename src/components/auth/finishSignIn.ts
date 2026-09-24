import { router } from "expo-router";
import { AuthApi, InvitationApi, type SessionBootstrap } from "@/api/client";
import { useSession } from "@/store/session";

export const normalizeCameroonPhone = (value: string) =>
  value.replace(/\s/g, "").replace(/^6/, "+2376").replace(/^2376/, "+2376");
export const isCameroonMobile = (e164: string) => /^\+2376\d{8}$/.test(e164);

/**
 * Shared tail of every sign-in path (password, OTP, reset, registration):
 * accept a pending partner invitation with the fresh bearer token, then
 * complete the session and open the workspace chooser.
 */
export async function finishSignIn(auth: SessionBootstrap, invite?: string) {
  let session = auth;
  let inviteError: string | null = null;
  if (invite) {
    try {
      await InvitationApi.accept(invite);
      session = await AuthApi.session();
    } catch (e) {
      inviteError = e instanceof Error ? e.message : "The invitation could not be accepted.";
    }
  }
  await useSession.getState().completeAuthentication(session);
  router.replace(
    inviteError
      ? { pathname: "/(auth)/invitation", params: { error: inviteError } }
      : "/(auth)/role",
  );
}
