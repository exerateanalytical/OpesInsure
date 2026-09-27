import React, { useState } from "react";
import { router } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { BrokerWorkspaceApi } from "@/api/partner";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * BRK-002 register a broker client (POST /mobile/broker/clients). The server
 * origin-locks the client to the broker and records the consent reference.
 */
export default function NewBrokerClient() {
  const { t } = useTranslation();
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [reference, setReference] = useState("");
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <Screen>
      <AppHeader title={t("agRegisterClientTitle")} subtitle={t("agClientMustConsent")} back />
      <Card>
        <TextField label={t("fullName")} value={name} onChangeText={setName} />
        <TextField label={t("agClientPhone")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
        <TextField label={t("city")} value={city} onChangeText={setCity} />
        <TextField label={t("bkConsentReference")} value={reference} onChangeText={setReference} />
        <Text style={s.body}>{t("agReadPrivacyNotice")}</Text>
        <ConsentCheckbox checked={consent} onChange={setConsent} label={t("agClientConsent")} />
        <Text style={s.body}>{t("agOriginLockRules")}</Text>
        <Notice text={error} tone="error" />
        <Button
          label={t("agCreateProtectedClient")}
          loading={busy}
          disabled={!consent || name.trim().length < 3 || phone.trim().length < 8 || city.trim().length < 2 || reference.trim().length < 3}
          onPress={async () => {
            setBusy(true);
            setError(null);
            try {
              const c = await BrokerWorkspaceApi.createClient({ full_name: name.trim(), phone_e164: phone.trim(), city: city.trim(), consent_reference: reference.trim() });
              router.replace(`/broker/clients/${c.id}`);
            } catch (e) {
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

const s = StyleSheet.create({ body: { ...type.body, color: colors.neutral600 } });
