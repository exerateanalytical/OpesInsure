import React, { useState } from "react";
import { router } from "expo-router";
import { Alert, StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { ApiError } from "@/api/client";
import { AgentWorkspaceApi } from "@/api/partner";
import { OfflineVault } from "@/offline/vault";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function NewAgentClient() {
  const { t } = useTranslation();
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <Screen>
      <AppHeader
        title={t("agRegisterClientTitle")}
        subtitle={t("agClientMustConsent")}
        back
      />
      <Card>
        <TextField label={t("fullName")} value={name} onChangeText={setName} />
        <TextField
          label={t("agClientPhone")}
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <TextField label={t("city")} value={city} onChangeText={setCity} />
        <Text style={s.body}>
          {t("agReadPrivacyNotice")}
        </Text>
        <ConsentCheckbox
          checked={consent}
          onChange={setConsent}
          label={t("agClientConsent")}
        />
        <Text style={s.body}>
          {t("agOriginLockRules")}
        </Text>
        <Notice text={error} tone="error" />
        <Button
          label={t("agCreateProtectedClient")}
          loading={busy}
          disabled={
            !consent ||
            name.trim().length < 3 ||
            phone.length < 8 ||
            city.length < 2
          }
          onPress={async () => {
            const payload = {
              full_name: name.trim(),
              phone_e164: phone.trim(),
              city: city.trim(),
              consent_confirmed: true as const,
            };
            setBusy(true);
            setError(null);
            try {
              const c = await AgentWorkspaceApi.createClient(payload);
              router.replace(`/agent/clients/${c.id}`);
            } catch (e) {
              if (e instanceof ApiError && e.status === 0) {
                // OFFLINE_QUEUE_FULL (localized) surfaces instead of crashing.
                const queued = await OfflineVault.enqueue({
                  kind: "MUTATION",
                  resource: t("agConsentedRegistration"),
                  method: "POST",
                  path: "/mobile/partner/agent/clients",
                  payload,
                }).catch((queueError: unknown) => {
                  setError(errorMessage(queueError));
                  return null;
                });
                if (!queued) return;
                Alert.alert(
                  t("agSavedForSync"),
                  t("agOfflineClientQueued"),
                  [
                    { text: t("agStayHere"), style: "cancel" },
                    { text: t("viewSync"), onPress: () => router.push("/sync") },
                  ],
                );
                return;
              }
              setError(errorMessage(e));
            } finally {
              setBusy(false);
            }
          }}
        />
      </Card>
    </Screen>
  );
}

const s = StyleSheet.create({
  body: { ...type.body, color: colors.neutral600 },
});
