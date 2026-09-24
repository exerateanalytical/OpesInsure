import React, { useEffect, useState } from "react";
import { LoadingState } from '@/components/StatePanel';
import { Text } from "react-native";
import * as DocumentPicker from "expo-document-picker";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { KycApi, KycProfile } from "@/api/client";
export default function Kyc() {
  const [p, setP] = useState<KycProfile>();
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    KycApi.profile().then(setP);
  }, []);
  if (!p)
    return (
      <Screen>
        <AppHeader title="Identity verification" back />
        <LoadingState label="Loading secure profile…" />
      </Screen>
    );
  return (
    <Screen>
      <AppHeader
        title="Identity verification"
        subtitle="Protect your account and policy ownership"
        back
      />
      <Card>
        <StatusChip
          label={p.status.replaceAll("_", " ")}
          tone={p.status === "VERIFIED" ? "success" : "warning"}
        />
        <TextField
          label="Legal name"
          value={p.legal_name}
          onChangeText={(legal_name) => setP({ ...p, legal_name })}
        />
        <TextField
          label="National ID / passport"
          value={p.national_id_number}
          onChangeText={(national_id_number) =>
            setP({ ...p, national_id_number })
          }
        />
        <TextField
          label="City"
          value={p.city}
          onChangeText={(city) => setP({ ...p, city })}
        />
        <Button
          label="Save identity details"
          variant="secondary"
          onPress={async () => setP(await KycApi.saveProfile(p))}
        />
      </Card>
      <Card>
        <Text>
          Upload a clear national ID or passport. Your document is encrypted in
          transit and reviewed only for insurance compliance.
        </Text>
        <Button
          label="Choose identity document"
          variant="secondary"
          onPress={async () => {
            const r = await DocumentPicker.getDocumentAsync({
              type: ["image/*", "application/pdf"],
            });
            if (!r.canceled) {
              const f = new FormData();
              f.append("document", r.assets[0] as any);
              await KycApi.uploadDocument(f);
            }
          }}
        />
        <Button
          label="Submit for verification"
          loading={busy}
          onPress={async () => {
            setBusy(true);
            setP(await KycApi.submit());
            setBusy(false);
          }}
        />
      </Card>
    </Screen>
  );
}
