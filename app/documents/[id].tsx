import React, { useEffect, useState } from "react";
import { Linking, Share, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { DocumentsApi, SecureDocument } from "@/api/client";
export default function DocumentPreview() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [d, setD] = useState<SecureDocument>();
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    DocumentsApi.show(id).then(setD);
  }, [id]);
  const access = async () => {
    setBusy(true);
    const x = await DocumentsApi.access(id);
    setD(x);
    setBusy(false);
    return x;
  };
  return (
    <Screen>
      <AppHeader
        title="Secure document"
        subtitle="Time-limited access is logged"
        back
      />
      <Card feature>
        <FileCheck2 size={36} />
        <StatusChip label={d?.status ?? "LOADING"} tone="success" />
        <Text>{d?.label}</Text>
        <Text>Reference: {d?.share_reference}</Text>
        <Text>Issued: {d?.issued_at?.slice(0, 10)}</Text>
      </Card>
      <Button
        label="Open protected document"
        loading={busy}
        onPress={async () => {
          const x = await access();
          if (x.signed_url) await Linking.openURL(x.signed_url);
        }}
      />
      <Button
        label="Share verification reference"
        variant="secondary"
        onPress={() =>
          Share.share({
            message: `OpesInsure document: ${d?.label}\nVerification reference: ${d?.share_reference}`,
          })
        }
      />
      <Text>
        Do not forward downloaded identity or claims documents to unknown
        recipients.
      </Text>
    </Screen>
  );
}
