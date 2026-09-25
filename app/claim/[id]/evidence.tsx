import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import * as DocumentPicker from "expo-document-picker";
import { Camera, FileUp, Images, Video } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { uploadClaimEvidence } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { claimActionAllowed } from "@/lib/claimStatus";
import { colors, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";

const MAX_VIDEO_SECONDS = 60;

export default function Evidence() {
  const { id, requirement } = useLocalSearchParams<{ id: string; requirement?: string }>();
  const { t, td, date } = useTranslation();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const items = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const allowed = claim.data ? claimActionAllowed("evidence", claim.data.status) : false;

  const upload = async (asset: { uri: string; mimeType?: string | null }, kind: string) => {
    if (!id) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    setProgress(0);
    try {
      await uploadClaimEvidence(id, asset, requirement ?? kind, setProgress);
      setNotice(t("evidenceUploaded"));
      await items.reload();
    } catch (e) {
      setError(e instanceof Error && e.message !== "FILE_READ_FAILED" ? e.message : t("evidenceUploadFailed"));
    } finally {
      setBusy(false);
      setProgress(null);
    }
  };

  const capture = async (media: "images" | "videos") => {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) {
      setError(t("cameraPermissionNeeded"));
      return;
    }
    const result = await withoutRelock(() => ImagePicker.launchCameraAsync({
      mediaTypes: [media],
      quality: 0.8,
      videoMaxDuration: MAX_VIDEO_SECONDS,
    }));
    const asset = result.assets?.[0];
    if (!result.canceled && asset)
      await upload(
        { uri: asset.uri, mimeType: asset.mimeType ?? (media === "videos" ? "video/mp4" : "image/jpeg") },
        media === "videos" ? "VIDEO" : "PHOTO",
      );
  };

  const library = async () => {
    const result = await withoutRelock(() => ImagePicker.launchImageLibraryAsync({
      mediaTypes: ["images", "videos"],
      quality: 0.8,
      videoMaxDuration: MAX_VIDEO_SECONDS,
    }));
    const asset = result.assets?.[0];
    if (!result.canceled && asset) {
      const video = asset.type === "video";
      await upload(
        { uri: asset.uri, mimeType: asset.mimeType ?? (video ? "video/mp4" : "image/jpeg") },
        video ? "VIDEO" : "PHOTO",
      );
    }
  };

  const document = async () => {
    const result = await withoutRelock(() => DocumentPicker.getDocumentAsync({
      type: ["application/pdf", "image/jpeg", "image/png"],
      copyToCacheDirectory: true,
      multiple: false,
    }));
    const asset = result.assets?.[0];
    if (!result.canceled && asset) await upload(asset, "DOCUMENT");
  };

  const declare = async () => {
    if (!claim.data) return;
    setBusy(true);
    setError(null);
    try {
      await ClaimsApi.submitDeclaration(claim.data.id);
      setNotice(t("evidenceDeclared"));
      await claim.reload();
    } catch (e) {
      setError(e instanceof Error ? e.message : t("evidenceDeclarationFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("evidenceTitle")} subtitle={claim.data?.claim_number} back />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {() =>
          allowed ? (
            <Card>
              <Text style={styles.title}>{t("evidenceCapture")}</Text>
              {requirement ? (
                <StatusChip label={t("evidenceForRequest", { name: td(`evidence_${requirement}`, requirement) })} tone="warning" />
              ) : null}
              <Text style={styles.body}>{t("evidenceCaptureBody")}</Text>
              <Button label={t("evidenceTakePhoto")} icon={Camera} loading={busy} onPress={() => void capture("images")} />
              <Button label={t("evidenceRecordVideo")} icon={Video} disabled={busy} variant="secondary" onPress={() => void capture("videos")} />
              <Button label={t("evidenceFromLibrary")} icon={Images} disabled={busy} variant="secondary" onPress={() => void library()} />
              <Button label={t("evidenceChooseDocument")} icon={FileUp} disabled={busy} variant="secondary" onPress={() => void document()} />
              <Text style={styles.meta}>{t("evidenceFormats", { seconds: MAX_VIDEO_SECONDS })}</Text>
              {progress !== null ? (
                <View
                  style={styles.track}
                  accessibilityRole="progressbar"
                  accessibilityValue={{ min: 0, max: 100, now: Math.round(progress * 100) }}
                >
                  <View style={[styles.bar, { width: `${Math.round(progress * 100)}%` }]} />
                </View>
              ) : null}
            </Card>
          ) : (
            <Card>
              <StatusChip label={t("evidenceLocked")} tone="neutral" />
              <Text style={styles.body}>{t("evidenceLockedBody")}</Text>
            </Card>
          )
        }
      </StatePanel>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
      <StatePanel
        {...items}
        onRetry={items.reload}
        emptyTitle={t("claimNoEvidence")}
        emptyMessage={t("claimNoEvidenceBody")}
        loadingLabel={t("loading")}
      >
        {(list) => (
          <>
            {list.map((item) => (
              <Card key={item.id}>
                <StatusChip
                  label={td(`evidenceStatus_${item.status}`, item.status)}
                  tone={item.status === "VERIFIED" ? "success" : item.status === "REJECTED" ? "danger" : "info"}
                />
                <Text style={styles.title}>{td(`evidence_${item.evidence_type}`, item.evidence_type)}</Text>
                <Text style={styles.body}>
                  {[item.mime_type, item.submitted_at ? date(item.submitted_at) : null].filter(Boolean).join(" · ")}
                </Text>
              </Card>
            ))}
          </>
        )}
      </StatePanel>
      {allowed ? (
        <Button label={t("evidenceDeclare")} variant="secondary" loading={busy} onPress={() => void declare()} />
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.neutral100, overflow: "hidden", marginTop: space.x2 },
  bar: { height: 8, backgroundColor: colors.blue600 },
});
