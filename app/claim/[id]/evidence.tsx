import React, { useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import * as DocumentPicker from "expo-document-picker";
import { Camera, FileUp } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { Claim, ClaimsApi } from "@/api/client";
import { colors, type } from "@/theme/tokens";
export default function Evidence() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [claim, setClaim] = useState<Claim | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (id)
      ClaimsApi.show(id)
        .then(setClaim)
        .catch((e) =>
          setError(e instanceof Error ? e.message : "Evidence unavailable."),
        );
  }, [id]);
  const upload = async (
    asset: { uri: string; name: string; mimeType?: string | null },
    type: string,
  ) => {
    if (!id) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append("type", type);
      form.append("file", {
        uri: asset.uri,
        name: asset.name,
        type: asset.mimeType ?? "application/octet-stream",
      } as any);
      await ClaimsApi.uploadEvidence(id, form);
      setClaim(await ClaimsApi.show(id));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Evidence upload failed.");
    } finally {
      setBusy(false);
    }
  };
  const photo = async () => {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) {
      setError("Camera permission is required to capture evidence.");
      return;
    }
    const result = await ImagePicker.launchCameraAsync({
      mediaTypes: ["images"],
      quality: 0.8,
    });
    const asset = result.assets?.[0];
    if (asset)
      await upload(
        {
          uri: asset.uri,
          name: asset.fileName ?? `evidence-${Date.now()}.jpg`,
          mimeType: asset.mimeType,
        },
        "PHOTO",
      );
  };
  const document = async () => {
    const result = await DocumentPicker.getDocumentAsync({
      copyToCacheDirectory: true,
      multiple: false,
    });
    const asset = result.assets?.[0];
    if (!result.canceled && asset) await upload(asset, "DOCUMENT");
  };
  return (
    <Screen>
      <AppHeader title="Claim evidence" subtitle={claim?.claim_number} back />
      <Card>
        <Text style={styles.title}>Capture evidence safely</Text>
        <Text style={styles.body}>
          Upload only material relevant to this incident. Original metadata and
          server audit history protect chain of custody.
        </Text>
        <Button
          label="Take a photo"
          icon={Camera}
          loading={busy}
          onPress={() => void photo()}
        />
        <Button
          label="Choose a document"
          icon={FileUp}
          loading={busy}
          variant="secondary"
          onPress={() => void document()}
        />
      </Card>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {claim?.evidence?.map((item) => (
        <Card key={item.id}>
          <StatusChip
            label={item.status}
            tone={item.status === "VERIFIED" ? "success" : "info"}
          />
          <Text style={styles.title}>{item.file_name}</Text>
          <Text style={styles.body}>
            {item.type} · {new Date(item.created_at).toLocaleString()}
          </Text>
        </Card>
      ))}
      {claim ? (
        <Button
          label="Submit evidence declaration"
          variant="secondary"
          onPress={async () => {
            setBusy(true);
            try {
              setClaim(await ClaimsApi.submitDeclaration(claim.id));
            } catch (e) {
              setError(e instanceof Error ? e.message : "Declaration failed.");
            } finally {
              setBusy(false);
            }
          }}
        />
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
