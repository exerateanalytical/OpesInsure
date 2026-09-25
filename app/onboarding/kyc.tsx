import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Camera, CheckCircle2, CircleAlert, Images, UserRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { ChoiceChips } from "@/components/portal/Workspace";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";
import { isKycReviewInProgress } from "@/lib/apiErrors";
import { canSubmitKyc, daysUntil, KYC_DOCUMENT_PURPOSES, KycPurpose, kycPhase, suggestedPurposes } from "@/lib/kyc";

/**
 * Identity verification on the KYC case engine (KycService):
 *  1. add an identifier: server form kyc_identifier -> PATCH /mobile/kyc/profile,
 *  2. photograph a document for a purpose (ID_FRONT, ID_BACK, PASSPORT,
 *     PROOF_OF_ADDRESS, RCCM, NIU) -> POST /mobile/documents -> POST /mobile/kyc/documents,
 *  3. submit / resubmit (POST /mobile/kyc/submission).
 * Shows kyc_level, requirements / missing_requirements and expires_at, and
 * every status: DRAFT, SUBMITTED, REVIEWING, MORE_INFO_REQUIRED (add documents
 * and resubmit), PENDING_APPROVAL, APPROVED, REJECTED, EXPIRED. A 409
 * KYC_REVIEW_IN_PROGRESS reloads the live submission instead of resubmitting.
 * With ?first=1 (right after sign-up) the step can be skipped.
 */
export default function Kyc() {
  const { first } = useLocalSearchParams<{ first?: string }>();
  const onboarding = first === "1";
  const { t, td, date } = useTranslation();
  const q = useLoad(() => CustomerApi.kyc());
  const [photo, setPhoto] = useState<{ base64: string; mime: "image/png" | "image/jpeg" } | null>(null);
  const [purpose, setPurpose] = useState<KycPurpose | null>(null);
  const [busy, setBusy] = useState<"photo" | "attach" | "submit" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const run = async (kind: "photo" | "attach" | "submit", fn: () => Promise<void>) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await fn();
    } catch (e) {
      const code = (e as { code?: string } | null)?.code;
      if (isKycReviewInProgress(code)) void q.reload();
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
  const attach = () =>
    run("attach", async () => {
      if (!photo || !purpose) throw new Error(t("kycChooseTypeFirst"));
      const doc = await CustomerApi.uploadDocument({ category: "KYC_IDENTITY", mime_type: photo.mime, file_base64: photo.base64 });
      await CustomerApi.attachKycDocument(doc.id, purpose);
      setPhoto(null);
      setPurpose(null);
      q.setData(await CustomerApi.kyc());
      setNotice(t("kycDocumentAdded"));
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
          const sub = k.submission;
          const status = sub?.status ?? "NOT_STARTED";
          const { phase, editable, tone } = kycPhase(status, sub?.expires_at);
          // A new verification starts from a fresh draft: old documents do not count.
          const restart = phase === "expired" || phase === "rejected";
          const docs = restart ? [] : (sub?.documents ?? []);
          const requirements = restart ? [] : (sub?.requirements ?? []);
          const suggested = suggestedPurposes(restart ? [] : sub?.missing_requirements);
          const days = daysUntil(sub?.expires_at);
          const ready = canSubmitKyc(editable, docs.length, requirements) && k.identifiers.length > 0;
          const reviewerNote = sub?.remediation_reason ?? (phase === "more_info" || phase === "rejected" ? sub?.notes : null);
          return (
            <>
              <Card>
                <StatusChip label={td(`kycStatus_${phase === "expired" ? "EXPIRED" : status}`, status)} tone={tone} />
                <Text style={styles.body}>{t(`kycPhase_${phase}`)}</Text>
                {sub?.kyc_level ? <Text style={styles.meta}>{t("kycLevel", { level: td(`kycLevel_${sub.kyc_level}`, sub.kyc_level) })}</Text> : null}
                {sub?.submitted_at ? <Text style={styles.meta}>{t("kycSubmittedOn", { date: date(sub.submitted_at) })}</Text> : null}
                {sub?.expires_at && phase === "approved" ? (
                  <Text style={days !== null && days <= 30 ? styles.warn : styles.meta}>
                    {days !== null && days <= 30 ? t("kycExpiresSoon", { days: Math.max(days, 0) }) : t("kycExpiresOn", { date: date(sub.expires_at) })}
                  </Text>
                ) : null}
                {phase === "expired" && sub?.expires_at ? <Text style={styles.warn}>{t("kycExpiredOn", { date: date(sub.expired_at ?? sub.expires_at) })}</Text> : null}
                {reviewerNote ? <Text style={styles.warn}>{t("kycReviewerNote", { note: reviewerNote })}</Text> : null}
              </Card>

              {requirements.length ? (
                <Card>
                  <Text style={styles.title}>{t("kycRequirementsTitle")}</Text>
                  {requirements.map((r) => (
                    <View key={`${r.requirement_code}-${r.applies_to ?? ""}`} style={styles.row}>
                      {r.satisfied ? <CheckCircle2 size={18} color={colors.success} /> : <CircleAlert size={18} color={r.mandatory ? colors.dangerText : colors.neutral500} />}
                      <Text style={styles.body}>
                        {td(`kycReq_${r.requirement_code}`, r.requirement_code)} · {t(r.satisfied ? "kycReqSatisfied" : r.mandatory ? "kycReqMissing" : "kycReqOptional")}
                      </Text>
                    </View>
                  ))}
                </Card>
              ) : null}

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
              {editable ? (
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
                    <Text style={styles.body}>
                      {td(`kycPurpose_${d.purpose}`, d.purpose)} · {td(`scan_${d.scan_status}`, d.scan_status)}
                    </Text>
                  </View>
                ))}
                {editable ? (
                  <>
                    {suggested.length ? (
                      <Text style={styles.meta}>{t("kycSuggested", { list: suggested.map((p) => td(`kycPurpose_${p}`, p)).join(", ") })}</Text>
                    ) : null}
                    <ChoiceChips<KycPurpose>
                      label={t("kycPurposeLabel")}
                      value={purpose}
                      onChange={setPurpose}
                      options={KYC_DOCUMENT_PURPOSES.map((p) => ({ value: p, label: td(`kycPurpose_${p}`, p) }))}
                    />
                    {photo ? <Text style={styles.body}>{t("kycPhotoReady")}</Text> : null}
                    <Button label={t("kycTakePhoto")} icon={Camera} variant="secondary" loading={busy === "photo"} disabled={!!busy} onPress={() => void takePhoto("camera")} />
                    <Button label={t("evidenceFromLibrary")} icon={Images} variant="tertiary" disabled={!!busy} onPress={() => void takePhoto("library")} />
                    <Button label={t("kycSaveDocument")} loading={busy === "attach"} disabled={!photo || !purpose || !!busy} onPress={() => void attach()} />
                    {!purpose ? <Text style={styles.meta}>{t("kycChooseTypeFirst")}</Text> : null}
                  </>
                ) : null}
              </Card>

              <Card>
                <Text style={styles.title}>{t("kycStep3")}</Text>
                <Text style={styles.body}>{t("kycProfileBody")}</Text>
                <Button label={t("personalInformation")} icon={UserRound} variant="tertiary" onPress={() => router.push("/account/profile")} />
                {editable ? (
                  <Button
                    label={t(phase === "more_info" ? "kycResubmit" : restart ? "kycRenew" : "kycSubmit")}
                    loading={busy === "submit"}
                    disabled={!ready || !!busy}
                    onPress={() => void submit()}
                  />
                ) : null}
                {editable && !ready ? <Text style={styles.meta}>{t("kycSubmitHint")}</Text> : null}
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
  warn: { ...type.meta, color: colors.warningText },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
