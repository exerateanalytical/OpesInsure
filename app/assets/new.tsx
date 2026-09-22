import React, { useState } from "react";
import { router } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AssetsApi } from "@/api/client";
export default function NewAsset() {
  const [label, setLabel] = useState("My vehicle");
  const [registration, setRegistration] = useState("");
  return (
    <Screen>
      <AppHeader
        title="Add a vehicle"
        subtitle="Enter details or scan the registration card"
        back
      />
      <Card>
        <TextField
          label="Vehicle nickname"
          value={label}
          onChangeText={setLabel}
        />
        <TextField
          label="Registration number"
          autoCapitalize="characters"
          value={registration}
          onChangeText={setRegistration}
        />
        <Button
          label="Save and scan documents"
          disabled={!registration.trim()}
          onPress={async () => {
            const a = await AssetsApi.create({
              label,
              registration_number: registration,
              status: "DRAFT",
            });
            router.replace(`/assets/${a.id}/scan`);
          }}
        />
      </Card>
    </Screen>
  );
}
