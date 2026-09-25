import React, { useState } from "react";
import { useLocalSearchParams, router } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AssetsApi, AssetDocument } from "@/api/client";
import { withoutRelock } from "@/lib/appLock";
import { useTranslation } from "@/i18n";
export default function Scan() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const [d, setD] = useState<AssetDocument>();
  const [f, setF] = useState<Record<string, string>>({});
  const capture = async () => {
    const p = await withoutRelock(() => ImagePicker.launchCameraAsync({
      mediaTypes: ["images"],
      quality: 0.8,
    }));
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
        title={t("scanTitle")}
        subtitle={t("scanSubtitle")}
        back
      />
      <Card>
        <Text>{t("scanHint")}</Text>
        <Button label={t("scanOpenCamera")} onPress={capture} />
      </Card>
      {d ? (
        <Card>
          <TextField
            label={t("scanRegistration")}
            value={f.registration_number}
            onChangeText={(v) => setF({ ...f, registration_number: v })}
          />
          <TextField
            label={t("scanMake")}
            value={f.make}
            onChangeText={(v) => setF({ ...f, make: v })}
          />
          <TextField
            label={t("scanModel")}
            value={f.model}
            onChangeText={(v) => setF({ ...f, model: v })}
          />
          <TextField
            label={t("scanYear")}
            keyboardType="number-pad"
            value={f.year}
            onChangeText={(v) => setF({ ...f, year: v })}
          />
          <Button
            label={t("scanConfirm")}
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
