import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Handshake, KeyRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { InvitationApi } from "@/api/client";
import { portalRoute, useSession } from "@/store/session";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { colors, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
/**
 * Insurers, brokers and agents cannot self-register: an OpesInsure or
 * institution administrator sends an invitation addressed to their phone or
 * email. This screen takes the invitation code. Signed out, it carries the
 * code through phone sign-in (verify.tsx accepts it right after the OTP);
 * signed in, it accepts immediately and opens the new workspace.
 */
export default function Invitation() {
  const params = useLocalSearchParams<{ token?: string; error?: string }>();
  const { t } = useTranslation();
  const status = useSession((s) => s.status);
  const refreshWorkspaces = useSession((s) => s.refreshWorkspaces);
  const [token, setToken] = useState(params.token ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(params.error ?? null);
  const signedIn = status === "authenticated";

  const submit = async () => {
    const value = token.trim();
    if (!/^\S{64}$/.test(value)) {
      setError(t("invCodeInvalid"));
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
      setError(e instanceof Error ? e.message : t("invAcceptFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("invTitle")} subtitle={t("invSubtitle")} back />
      <Card feature>
        <Handshake size={32} color={colors.blue600} />
        <Text style={styles.title}>{t("invHow")}</Text>
        <Text style={styles.body}>{t("invBody1")}</Text>
        <Text style={styles.body}>{t("invSteps")}</Text>
      </Card>
      <Card>
        <TextField
          label={t("invCode")}
          value={token}
          onChangeText={setToken}
          autoCapitalize="none"
          autoCorrect={false}
          placeholder={t("invCodePlaceholder")}
          error={error ?? undefined}
        />
        <Button
          label={signedIn ? t("invAccept") : t("invContinue")}
          icon={KeyRound}
          loading={busy}
          disabled={!token.trim()}
          onPress={() => void submit()}
        />
        <Text style={styles.meta}>{t("invNoInvite")}</Text>
        <SupportContactList partner />
        {!signedIn ? (
          <Button
            label={t("invCustomerInstead")}
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
