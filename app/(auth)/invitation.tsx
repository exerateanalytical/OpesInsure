import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Handshake, KeyRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { InvitationApi } from "@/api/client";
import { portalRoute, useSession } from "@/store/session";
import { colors, type } from "@/theme/tokens";

/**
 * Insurers, brokers and agents cannot self-register: an OpesInsure or
 * institution administrator sends an invitation addressed to their phone or
 * email. This screen takes the invitation code. Signed out, it carries the
 * code through phone sign-in (verify.tsx accepts it right after the OTP);
 * signed in, it accepts immediately and opens the new workspace.
 */
export default function Invitation() {
  const params = useLocalSearchParams<{ token?: string; error?: string }>();
  const status = useSession((s) => s.status);
  const refreshWorkspaces = useSession((s) => s.refreshWorkspaces);
  const [token, setToken] = useState(params.token ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(params.error ?? null);
  const signedIn = status === "authenticated";

  const submit = async () => {
    const value = token.trim();
    if (!/^\S{64}$/.test(value)) {
      setError("Enter the full 64-character invitation code from your invitation message.");
      return;
    }
    setError(null);
    if (!signedIn) {
      router.push({ pathname: "/(auth)/sign-in", params: { invite: value } });
      return;
    }
    setBusy(true);
    try {
      const membership = await InvitationApi.accept(value);
      const bootstrap = await refreshWorkspaces(membership.tenant_id);
      const active = useSession.getState().activeWorkspace;
      if (active) router.replace(portalRoute(active));
      else if (bootstrap.workspaces.length) router.replace("/(auth)/role");
      else router.replace("/access-denied");
    } catch (e) {
      setError(e instanceof Error ? e.message : "The invitation could not be accepted.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title="Partners join by invitation" subtitle="Insurers, brokers and agents" back />
      <Card feature>
        <Handshake size={32} color={colors.blue600} />
        <Text style={styles.title}>How partner onboarding works</Text>
        <Text style={styles.body}>
          Insurance companies, brokers and agents are licensed and verified
          before they can sell or service policies on OpesInsure. Your
          institution administrator, or the OpesInsure partnerships team,
          sends an invitation to your mobile number or email address.
        </Text>
        <Text style={styles.body}>
          1. Enter the invitation code below.{"\n"}
          2. Sign in with the phone number the invitation was sent to.{"\n"}
          3. Your partner workspace opens automatically.
        </Text>
      </Card>
      <Card>
        <TextField
          label="Invitation code"
          value={token}
          onChangeText={setToken}
          autoCapitalize="none"
          autoCorrect={false}
          placeholder="Paste the code from your invitation"
          error={error ?? undefined}
        />
        <Button
          label={signedIn ? "Accept invitation" : "Continue to sign in"}
          icon={KeyRound}
          loading={busy}
          disabled={!token.trim()}
          onPress={() => void submit()}
        />
        <Text style={styles.meta}>
          No invitation yet? Contact partners@opesinsure.cm to start licence
          verification. Customers can create an account directly.
        </Text>
        {!signedIn ? (
          <Button
            label="Create a customer account instead"
            variant="tertiary"
            onPress={() => router.replace("/(auth)/sign-up")}
          />
        ) : null}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
});
