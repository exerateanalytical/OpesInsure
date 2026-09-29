import React, { useState } from "react";
import { useLocalSearchParams, router } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Text } from "react-native";
import { FileText, ScanLine } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewDocuments, ReviewFooter, ReviewIntro, ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
import { AssetsApi, AssetDocument } from "@/api/client";
import { withoutRelock } from "@/lib/appLock";
import { useTranslation } from "@/i18n";

const SCAN_FIELDS = [
  ["registration_number", "scanRegistration"],
  ["make", "scanMake"],
  ["model", "scanModel"],
  ["year", "scanYear"],
] as const;

export default function Scan() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const [d, setD] = useState<AssetDocument>();
  const [f, setF] = useState<Record<string, string>>({});
  const [photo, setPhoto] = useState<string | null>(null);
  // The extracted details are shown read-only for a last check before they are confirmed.
  const [reviewing, setReviewing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
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
      setPhoto(p.assets[0].uri);
      setF(doc.extracted_fields ?? {});
    }
  };
  const confirm = async () => {
    if (!d) return;
    setBusy(true);
    setError(null);
    try {
      await AssetsApi.confirmScan(id, d.id, f);
      router.replace(`/assets/${id}`);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  if (d && reviewing)
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
        <Button label={t("scanOpenCamera")} onPress={capture} />
      </Card>
      {d ? (
        <Card>
          {SCAN_FIELDS.map(([k, label]) => (
            <TextField
              key={k}
              label={t(label)}
              keyboardType={k === "year" ? "number-pad" : "default"}
              value={f[k]}
              onChangeText={(v) => setF({ ...f, [k]: v })}
            />
          ))}
          <Button
            label={t("reviewBeforeSave")}
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
