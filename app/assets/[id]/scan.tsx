import React, { useState } from "react";
import { useLocalSearchParams, router } from "expo-router";
import { Text } from "react-native";
import { FileText, ScanLine } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewDocuments, ReviewFooter, ReviewIntro, ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
import { ApiError, AssetsApi, type AssetScanState } from "@/api/client";
import { pickUpload, storeDocument } from "@/api/documentUpload";
import { REGISTRATION_PURPOSE, scanFacts, scanPrefill, type ScanFactKey } from "@/lib/assetScan";
import { useTranslation } from "@/i18n";

const SCAN_FIELDS: readonly (readonly [ScanFactKey, "scanRegistration" | "scanMake" | "scanModel" | "scanYear"])[] = [
  ["registration_number", "scanRegistration"],
  ["make", "scanMake"],
  ["model", "scanModel"],
  ["year", "scanYear"],
] as const;

export default function Scan() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  // The asset after the card was attached (carries the version confirmScan must echo).
  const [asset, setAsset] = useState<AssetScanState>();
  const [documentId, setDocumentId] = useState<string | null>(null);
  const [f, setF] = useState<Partial<Record<ScanFactKey, string>>>({});
  const [photo, setPhoto] = useState<string | null>(null);
  // The extracted details are shown read-only for a last check before they are confirmed.
  const [reviewing, setReviewing] = useState(false);
  const [capturing, setCapturing] = useState(false);
  const [captureError, setCaptureError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const capture = async () => {
    if (capturing) return;
    setCapturing(true);
    setCaptureError(null);
    try {
      const file = await pickUpload("camera");
      if (!file) return;
      // Store the photo like every other upload, then link it to this vehicle.
      const docId = await storeDocument("VEHICLE_REGISTRATION", file);
      let state = await AssetsApi.attachDocument(id, docId, REGISTRATION_PURPOSE).catch(async (e: unknown): Promise<AssetScanState> => {
        // Already attached (same photo again): carry on with the asset as it stands.
        if (!(e instanceof ApiError) || e.status !== 409) throw e;
        const current = await AssetsApi.show(id);
        if (typeof current.version !== "number") throw e;
        return { ...current, version: current.version };
      });
      // OCR only runs once the malware scan passed; until then the customer types the details.
      state = await AssetsApi.scan(id, docId).catch(() => state);
      setAsset(state);
      setDocumentId(docId);
      setPhoto(`data:${file.mime};base64,${file.base64}`);
      setF(scanPrefill(state, docId));
    } catch (e) {
      setCaptureError(e);
    } finally {
      setCapturing(false);
    }
  };
  const confirm = async () => {
    if (!asset || !documentId) return;
    setBusy(true);
    setError(null);
    try {
      await AssetsApi.confirmScan(id, documentId, asset.version, scanFacts(f));
      router.replace(`/assets/${id}`);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  const ready = !!asset && !!documentId;
  if (ready && reviewing)
    return (
      <Screen footer={<ReviewFooter label={t("scanConfirm")} loading={busy} onConfirm={() => void confirm()} onBack={() => setReviewing(false)} />}>
        <AppHeader title={t("scanTitle")} subtitle={t("scanSubtitle")} back />
        <ReviewIntro body={t("reviewSaveIntro")} />
        <ReviewSection icon={ScanLine} title={t("scanReviewFields")} onEdit={() => setReviewing(false)}>
          <ReviewRows rows={SCAN_FIELDS.map(([k, label]) => ({ key: k, label: t(label), value: String(f[k] ?? "").trim() || null }))} />
        </ReviewSection>
        {photo ? (
          <ReviewSection icon={FileText} title={t("scanReviewPhoto")}>
            <ReviewDocuments files={[{ key: "registration", name: t("scanReviewPhoto"), uri: photo, image: true }]} />
          </ReviewSection>
        ) : null}
        {error ? <ErrorCard error={error} fallback={t("errGeneric")} onRetry={() => void confirm()} /> : null}
      </Screen>
    );
  return (
    <Screen>
      <AppHeader
        title={t("scanTitle")}
        subtitle={t("scanSubtitle")}
        back
      />
      <Card>
        <Text>{t("scanHint")}</Text>
        <Button label={t("scanOpenCamera")} loading={capturing} disabled={capturing} onPress={() => void capture()} />
      </Card>
      {captureError ? <ErrorCard error={captureError} fallback={t("errGeneric")} onRetry={() => void capture()} /> : null}
      {ready ? (
        <Card>
          {SCAN_FIELDS.map(([k, label]) => (
            <TextField
              key={k}
              label={t(label)}
              keyboardType={k === "year" ? "number-pad" : "default"}
              value={f[k] ?? ""}
              onChangeText={(v) => setF({ ...f, [k]: v })}
            />
          ))}
          <Button
            label={t("reviewBeforeSave")}
            disabled={Object.keys(scanFacts(f)).length === 0}
            onPress={() => {
              setError(null);
              setReviewing(true);
            }}
          />
        </Card>
      ) : null}
    </Screen>
  );
}
