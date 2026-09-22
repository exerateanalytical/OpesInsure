import React, { useState } from "react";
import { useLocalSearchParams, router } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AssetsApi, AssetDocument } from "@/api/client";
export default function Scan() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [d, setD] = useState<AssetDocument>();
  const [f, setF] = useState<Record<string, string>>({});
  const capture = async () => {
    const p = await ImagePicker.launchCameraAsync({
      mediaTypes: ["images"],
      quality: 0.8,
    });
    if (!p.canceled && p.assets[0]) {
      const form = new FormData();
      form.append("document", {
        uri: p.assets[0].uri,
        name: "registration.jpg",
        type: "image/jpeg",
      } as any);
      const doc = await AssetsApi.uploadDocument(id, form);
      setD(doc);
      setF(doc.extracted_fields ?? {});
    }
  };
  return (
    <Screen>
      <AppHeader
        title="Scan registration card"
        subtitle="Review every extracted field before confirming"
        back
      />
      <Card>
        <Text>
          Place the full document inside the frame. Avoid glare and blur.
        </Text>
        <Button label="Open camera" onPress={capture} />
      </Card>
      {d ? (
        <Card>
          <TextField
            label="Registration"
            value={f.registration_number}
            onChangeText={(v) => setF({ ...f, registration_number: v })}
          />
          <TextField
            label="Make"
            value={f.make}
            onChangeText={(v) => setF({ ...f, make: v })}
          />
          <TextField
            label="Model"
            value={f.model}
            onChangeText={(v) => setF({ ...f, model: v })}
          />
          <TextField
            label="Year"
            keyboardType="number-pad"
            value={f.year}
            onChangeText={(v) => setF({ ...f, year: v })}
          />
          <Button
            label="Confirm extracted details"
            onPress={async () => {
              await AssetsApi.confirmScan(id, d.id, f);
              router.replace(`/assets/${id}`);
            }}
          />
        </Card>
      ) : null}
    </Screen>
  );
}
