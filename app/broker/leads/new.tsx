import React, { useState } from "react";
import { router } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { BrokerWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** BRK-002 new broker lead (POST /crm/leads); the server scopes it to the broker's book. */
export default function NewBrokerLead() {
  const { t } = useTranslation();
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [interest, setInterest] = useState("");
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <Screen>
      <AppHeader title={t("leadNewTitle")} subtitle={t("leadNewSubtitle")} back />
      <Card>
        <TextField label={t("fullName")} value={name} onChangeText={setName} />
        <TextField label={t("agClientPhone")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
        <TextField label={t("city")} value={city} onChangeText={setCity} />
        <TextField label={t("bkProductInterest")} value={interest} onChangeText={setInterest} />
        <TextField label={t("bkNotes")} value={notes} onChangeText={setNotes} multiline />
        <Notice text={error} tone="error" />
        <Button
          label={t("leadSave")}
          loading={busy}
          disabled={name.trim().length < 2 || !/^\+[1-9]\d{6,14}$/.test(phone.trim())}
          onPress={async () => {
            setBusy(true);
            setError(null);
            try {
              const lead = await BrokerWorkspaceApi.createLead({
                full_name: name.trim(),
                phone_e164: phone.trim(),
                ...(city.trim() ? { city: city.trim() } : {}),
                ...(interest.trim() ? { product_interest: interest.trim().slice(0, 32) } : {}),
                ...(notes.trim() ? { notes: notes.trim() } : {}),
                source: "BROKER",
              });
              router.replace(`/broker/leads/${lead.id}`);
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
