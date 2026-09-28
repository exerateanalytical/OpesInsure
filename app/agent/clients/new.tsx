import React, { useState } from "react";
import { router } from "expo-router";
import { Alert, StyleSheet, Text } from "react-native";
import { TextField } from "@/components/ui";
import { AgentButton, AgentCard, AgentShell } from "@/components/agent";
import { UserPlus } from "lucide-react-native";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { ApiError } from "@/api/client";
import { AgentWorkspaceApi } from "@/api/partner";
import { OfflineVault } from "@/offline/vault";
import { agentColors as c, agentType as T } from "@/theme/agent";
import { useTranslation } from "@/i18n";

export default function NewAgentClient() {
  const { t } = useTranslation();
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const submit = async () => {
    const payload = {
      full_name: name.trim(),
      phone_e164: phone.trim(),
      city: city.trim(),
      consent_confirmed: true as const,
    };
    setBusy(true);
    setError(null);
    try {
      const created = await AgentWorkspaceApi.createClient(payload);
      router.replace(`/agent/clients/${created.id}`);
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
  };
  const disabled = !consent || name.trim().length < 3 || phone.length < 8 || city.length < 2;
  return (
    <AgentShell
      variant="drilldown"
      title={t("agRegisterClientTitle")}
      hideNav
      footer={<AgentButton icon={UserPlus} label={t("agCreateProtectedClient")} loading={busy} disabled={disabled} onPress={submit} />}
    >
      <Text style={s.lead}>{t("agClientMustConsent")}</Text>
      <AgentCard style={s.form}>
        <TextField label={t("fullName")} value={name} onChangeText={setName} />
        <TextField
          label={t("agClientPhone")}
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <TextField label={t("city")} value={city} onChangeText={setCity} />
      </AgentCard>
      <AgentCard style={s.form}>
        <Text style={s.body}>{t("agReadPrivacyNotice")}</Text>
        <ConsentCheckbox
          checked={consent}
          onChange={setConsent}
          label={t("agClientConsent")}
        />
        <Text style={s.body}>{t("agOriginLockRules")}</Text>
      </AgentCard>
      <Notice text={error} tone="error" />
    </AgentShell>
  );
}

const s = StyleSheet.create({
  lead: { ...T.secondary, color: c.secondary },
  form: { gap: 16 },
  body: { ...T.secondary, color: c.secondary },
});
