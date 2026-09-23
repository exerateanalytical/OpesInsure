import React, { useState } from "react";
import { router } from "expo-router";
import { Alert, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AgentApi, ApiError } from "@/api/client";
import { OfflineVault } from "@/offline/vault";
export default function NewAgentClient() {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [consent, setConsent] = useState(false);
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
        <Button
          label={consent ? "Consent recorded" : "Record client consent"}
          variant={consent ? "secondary" : "primary"}
          onPress={() => setConsent(true)}
        />
        <Text>
          The backend must detect existing customers and apply the permanent
          origin-lock rules. The agent cannot overwrite another partner’s
          ownership.
        </Text>
        <Button
          label="Create protected client"
          disabled={
            !consent ||
            name.trim().length < 3 ||
            phone.length < 8 ||
            city.length < 2
          }
          onPress={async () => {
            const payload = {
              full_name: name,
              phone_e164: phone,
              city,
              consent_reference: `CONSENT-${Date.now()}`,
            };
            try {
              const c = await AgentApi.createClient(payload);
              router.replace(`/agent/clients/${c.id}`);
            } catch (error) {
              if (error instanceof ApiError && error.status === 0) {
                await OfflineVault.enqueue({
                  kind: "MUTATION",
                  resource: "Consented agent client registration",
                  method: "POST",
                  path: "/mobile/agent/clients",
                  payload,
                });
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
              throw error;
            }
          }}
        />
      </Card>
    </Screen>
  );
}
