import React, { useState } from "react";
import { router } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AgentApi } from "@/api/client";
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
            const c = await AgentApi.createClient({
              full_name: name,
              phone_e164: phone,
              city,
              consent_reference: `CONSENT-${Date.now()}`,
            });
            router.replace(`/agent/clients/${c.id}`);
          }}
        />
      </Card>
    </Screen>
  );
}
