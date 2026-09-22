import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { ClaimParty, ClaimsCompletionApi } from "@/api/client";
export default function Parties() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimParty[]>([]);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  useEffect(() => {
    ClaimsCompletionApi.parties(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader
        title="People involved"
        subtitle="Drivers, third parties, passengers and witnesses"
        back
      />
      {x.map((p) => (
        <Card key={p.id}>
          <StatusChip label={p.role.replaceAll("_", " ")} tone="info" />
          <Text>{p.full_name}</Text>
          <Text>{p.phone_e164}</Text>
          <Text>{p.vehicle_registration}</Text>
        </Card>
      ))}
      <Card>
        <Text>Add a witness</Text>
        <TextField label="Full name" value={name} onChangeText={setName} />
        <TextField
          label="Phone number"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Button
          label="Add witness"
          variant="secondary"
          disabled={name.trim().length < 3}
          onPress={async () => {
            const p = await ClaimsCompletionApi.addParty(id, {
              role: "WITNESS",
              full_name: name,
              phone_e164: phone,
            });
            setX([...x, p]);
            setName("");
            setPhone("");
          }}
        />
      </Card>
      <Button
        label="Continue to evidence checklist"
        onPress={() => router.push(`/claim/${id}/checklist`)}
      />
    </Screen>
  );
}
