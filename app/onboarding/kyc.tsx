import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Camera, CheckCircle2, Images, UserRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";

/**
 * Identity verification (MobileKycService):
 *  1. add an identifier: server form kyc_identifier -> PATCH /mobile/kyc/profile,
 *  2. photograph the document: form kyc_document (type picker) + photo
 *     -> POST /mobile/documents -> POST /mobile/kyc/documents,
 *  3. submit (POST /mobile/kyc/submission). Address, occupation and
 *     beneficiaries now live on the server profile (form customer_profile).
 * With ?first=1 (right after sign-up) the step can be skipped and finished
 * later from Profile.
 */
export default function Kyc() {
  const { first } = useLocalSearchParams<{ first?: string }>();
  const onboarding = first === "1";
  const { t, td, date } = useTranslation();
  const q = useLoad(() => CustomerApi.kyc());
  const [photo, setPhoto] = useState<{ base64: string; mime: "image/png" | "image/jpeg" } | null>(null);
  const [busy, setBusy] = useState<"photo" | "submit" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const run = async (kind: "photo" | "submit", fn: () => Promise<void>) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(null);
    }
  };
  const takePhoto = (source: "camera" | "library") =>
    run("photo", async () => {
      if (source === "camera") {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) throw new Error(t("cameraPermissionNeeded"));
      }
      const options: ImagePicker.ImagePickerOptions = { mediaTypes: ["images"], quality: 0.7, base64: true };
      const result = source === "camera" ? await withoutRelock(() => ImagePicker.launchCameraAsync(options)) : await withoutRelock(() => ImagePicker.launchImageLibraryAsync(options));
      const asset = result.assets?.[0];
      if (result.canceled || !asset?.base64) return;
      setPhoto({ base64: asset.base64, mime: asset.mimeType === "image/png" ? "image/png" : "image/jpeg" });
      setNotice(t("kycPhotoReady"));
    });
  const submit = () =>
    run("submit", async () => {
      await CustomerApi.submitKyc();
      q.setData(await CustomerApi.kyc());
      setNotice(t("kycSubmitted"));
    });
  const finish = () => router.replace("/(customer)/(tabs)");

  return (
    <Screen>
      <AppHeader
        title={onboarding ? t("kycWelcomeTitle") : t("identityVerification")}
        subtitle={onboarding ? t("kycWelcomeSubtitle") : t("kycSubtitle")}
        back={!onboarding}
      />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(k) => {
          const status = k.submission?.status ?? "NOT_STARTED";
          const locked = status === "SUBMITTED" || status === "UNDER_REVIEW" || status === "APPROVED" || status === "VERIFIED";
          const docs = k.submission?.documents ?? [];
          return (
            <>
              <Card>
                <StatusChip
                  label={td(`kycStatus_${status}`, status)}
                  tone={status === "APPROVED" || status === "VERIFIED" ? "success" : status === "REJECTED" ? "danger" : locked ? "info" : "warning"}
                />
                <Text style={styles.body}>{t(locked ? "kycLockedBody" : "kycIntro")}</Text>
                {k.submission?.submitted_at ? <Text style={styles.meta}>{t("kycSubmittedOn", { date: date(k.submission.submitted_at) })}</Text> : null}
              </Card>

              <Card>
                <Text style={styles.title}>{t("kycStep1")}</Text>
                {k.identifiers.map((i) => (
                  <View key={`${i.type}-${i.masked_value}`} style={styles.row}>
                    <CheckCircle2 size={18} color={i.verified_at ? colors.success : colors.neutral500} />
                    <Text style={styles.body}>
                      {td(`idType_${i.type}`, i.type)} · {i.masked_value}
                    </Text>
                  </View>
                ))}
              </Card>
              {!locked ? (
                <SchemaForm
                  form="kyc_identifier"
                  submitLabel={t("kycSaveIdentifier")}
                  resetOnSuccess
                  onSubmit={async (payload) => {
                    setError(null);
                    q.setData(await CustomerApi.addIdentifier(payload as Parameters<typeof CustomerApi.addIdentifier>[0]));
                    setNotice(t("kycIdentifierSaved"));
                  }}
                />
              ) : null}

              <Card>
                <Text style={styles.title}>{t("kycStep2")}</Text>
                <Text style={styles.body}>{t("kycDocumentBody")}</Text>
                {docs.map((d) => (
                  <View key={d.id} style={styles.row}>
                    <CheckCircle2 size={18} color={d.scan_status === "CLEAN" ? colors.success : colors.neutral500} />
                    <Text style={styles.body}>{td(`scan_${d.scan_status}`, d.scan_status)}</Text>
                  </View>
                ))}
                {!locked ? <Text style={styles.meta}>{t("kycChooseTypeFirst")}</Text> : null}
              </Card>
              {!locked ? (
                <SchemaForm
                  form="kyc_document"
                  hide={["document_id"]}
                  submitLabel={t("kycSaveDocument")}
                  disabled={!photo}
                  resetOnSuccess
                  footer={
                    <Card>
                      {photo ? <Text style={styles.body}>{t("kycPhotoReady")}</Text> : null}
                      <Button label={t("kycTakePhoto")} icon={Camera} variant="secondary" loading={busy === "photo"} disabled={!!busy} onPress={() => void takePhoto("camera")} />
                      <Button label={t("evidenceFromLibrary")} icon={Images} variant="tertiary" disabled={!!busy} onPress={() => void takePhoto("library")} />
                    </Card>
                  }
                  onSubmit={async ({ purpose, ...rest }) => {
                    if (!photo) throw new Error(t("kycChooseTypeFirst"));
                    const doc = await CustomerApi.uploadDocument({ category: "KYC_IDENTITY", mime_type: photo.mime, file_base64: photo.base64 });
                    await CustomerApi.attachKycDocument(doc.id, String(purpose ?? "IDENTITY_DOCUMENT"), rest);
                    setPhoto(null);
                    q.setData(await CustomerApi.kyc());
                    setNotice(t("kycDocumentAdded"));
                  }}
                />
              ) : null}

              <Card>
                <Text style={styles.title}>{t("kycStep3")}</Text>
                <Text style={styles.body}>{t("kycProfileBody")}</Text>
                <Button label={t("personalInformation")} icon={UserRound} variant="tertiary" onPress={() => router.push("/account/profile")} />
                {!locked ? (
                  <Button
                    label={t("kycSubmit")}
                    loading={busy === "submit"}
                    disabled={!k.identifiers.length || !docs.length || !!busy}
                    onPress={() => void submit()}
                  />
                ) : null}
                {!locked && (!k.identifiers.length || !docs.length) ? <Text style={styles.meta}>{t("kycSubmitHint")}</Text> : null}
              </Card>
            </>
          );
        }}
      </StatePanel>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
      {onboarding ? (
        <>
          <Button label={t("kycDone")} onPress={finish} />
          <Button label={t("kycSkip")} variant="tertiary" onPress={finish} />
          <Text style={styles.meta}>{t("kycLaterNote")}</Text>
        </>
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
