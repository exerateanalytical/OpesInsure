import React, { useState } from "react";
import { router } from "expo-router";
import { Alert, StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { ApiError } from "@/api/client";
import { AgentWorkspaceApi } from "@/api/partner";
import { OfflineVault } from "@/offline/vault";
import { colors, type } from "@/theme/tokens";

export default function NewAgentClient() {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <Screen>
      <AppHeader
        title="Register client"
        subtitle="The client must consent before their record is created"
        back
      />
      <Card>
        <TextField label="Full name" value={name} onChangeText={setName} />
        <TextField
          label="Client phone"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <TextField label="City" value={city} onChangeText={setCity} />
        <Text style={s.body}>
          Read the privacy notice to the client and ask for their agreement.
          The consent record and its reference are issued by the server — you
          never type or invent one.
        </Text>
        <ConsentCheckbox
          checked={consent}
          onChange={setConsent}
          label="The client agreed to OpesInsure processing their data to arrange insurance."
        />
        <Text style={s.body}>
          The backend detects existing customers and applies the permanent
          origin-lock rules. An agent cannot overwrite another partner’s
          ownership.
        </Text>
        <Notice text={error} tone="error" />
        <Button
          label="Create protected client"
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
                  resource: "Consented agent client registration",
                  method: "POST",
                  path: "/mobile/partner/agent/clients",
                  payload,
                }).catch((queueError: unknown) => {
                  setError(errorMessage(queueError));
                  return null;
                });
                if (!queued) return;
                Alert.alert(
                  "Saved for sync",
                  "You're offline, so this client record will be created once the connection is back. It stays queued in Sync Centre until then.",
                  [
                    { text: "Stay here", style: "cancel" },
                    { text: "View sync", onPress: () => router.push("/sync") },
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
