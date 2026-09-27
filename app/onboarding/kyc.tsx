import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { BadgeCheck, Camera, CheckCircle2, CircleAlert, ClipboardList, Fingerprint, IdCard, Images, ShieldAlert, ShieldCheck, UserRound } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon, type Tint } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { ChoiceChips } from "@/components/portal/Workspace";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";
import { isKycReviewInProgress } from "@/lib/apiErrors";
import { canSubmitKyc, daysUntil, KYC_DOCUMENT_PURPOSES, KycPurpose, kycPhase, REQUIREMENT_PURPOSES, suggestedPurposes } from "@/lib/kyc";

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
const toneTint = (tone: string): Tint => (tone === "success" ? "green" : tone === "warning" ? "gold" : tone === "danger" ? "red" : tone === "info" ? "blue" : "neutral");

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
      <BrandHeader
        title={onboarding ? t("kycWelcomeTitle") : t("identityVerification")}
        subtitle={onboarding ? t("kycWelcomeSubtitle") : t("kycSubtitle")}
        back={!onboarding}
        right="help"
      />
      <BrandArt name="glass_shield" width={64} />
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
          const expiringSoon = days !== null && days <= 30;
          return (
            <>
              <Card style={styles.card}>
                {requirements.length ? (
                  <View style={styles.progressBlock}>
                    <Text style={styles.metaLabel}>{t("kycStatusLabel")}</Text>
                    <Text style={styles.progressTitle}>
                      {t("kycReqProgress", { done: requirements.filter((r) => r.satisfied).length, total: requirements.length })}
                    </Text>
                    <View style={styles.track} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: requirements.length, now: requirements.filter((r) => r.satisfied).length }}>
                      <View style={[styles.fill, { width: `${Math.round((100 * requirements.filter((r) => r.satisfied).length) / requirements.length)}%` }]} />
                    </View>
                  </View>
                ) : null}
                <View style={styles.headRow}>
                  <TintedIcon icon={phase === "approved" ? BadgeCheck : restart ? ShieldAlert : ShieldCheck} tint={toneTint(tone)} size={56} />
                  <View style={styles.flex}>
                    <StatusChip label={td(`kycStatus_${phase === "expired" ? "EXPIRED" : status}`, status)} tone={tone} />
                    <Text style={styles.body}>{t(`kycPhase_${phase}`)}</Text>
                  </View>
                </View>
                {sub?.kyc_level || sub?.submitted_at || (sub?.expires_at && phase === "approved") ? (
                  <View style={styles.metaBlock}>
                    {sub?.kyc_level ? <Text style={styles.meta}>{t("kycLevel", { level: td(`kycLevel_${sub.kyc_level}`, sub.kyc_level) })}</Text> : null}
                    {sub?.submitted_at ? <Text style={styles.meta}>{t("kycSubmittedOn", { date: date(sub.submitted_at) })}</Text> : null}
                    {sub?.expires_at && phase === "approved" ? (
                      <Text style={expiringSoon ? styles.warn : styles.meta}>
                        {expiringSoon ? t("kycExpiresSoon", { days: Math.max(days, 0) }) : t("kycExpiresOn", { date: date(sub.expires_at) })}
                      </Text>
                    ) : null}
                  </View>
                ) : null}
                {phase === "expired" && sub?.expires_at ? <Banner icon={CircleAlert} tint="gold" body={t("kycExpiredOn", { date: date(sub.expired_at ?? sub.expires_at) })} /> : null}
                {reviewerNote ? <Banner icon={CircleAlert} tint="gold" body={t("kycReviewerNote", { note: reviewerNote })} /> : null}
              </Card>

              {requirements.length ? (
                <View style={styles.reqList}>
                  <SectionHeading title={t("kycRequirementsTitle")} icon={ClipboardList} />
                  {requirements.map((r) => {
                    const target = REQUIREMENT_PURPOSES[r.requirement_code]?.[0];
                    return (
                      <Card key={`${r.requirement_code}-${r.applies_to ?? ""}`} style={[styles.card, styles.reqRow]}>
                        <TintedIcon icon={r.satisfied ? CheckCircle2 : IdCard} tint={r.satisfied ? "green" : r.mandatory ? "gold" : "neutral"} size={44} />
                        <View style={styles.flexTight}>
                          <Text style={styles.reqTitle}>{td(`kycReq_${r.requirement_code}`, r.requirement_code)}</Text>
                          <Text style={r.satisfied ? styles.ok : r.mandatory ? styles.warn : styles.meta}>
                            {t(r.satisfied ? "kycReqSatisfied" : r.mandatory ? "kycReqMissing" : "kycReqOptional")}
                          </Text>
                        </View>
                        {!r.satisfied && editable && target ? (
                          <Button label={t("kycComplete")} variant="secondary" onPress={() => setPurpose(target)} />
                        ) : null}
                      </Card>
                    );
                  })}
                </View>
              ) : null}

              <Banner icon={ShieldCheck} tint="blue" title={t("kycWhyTitle")} body={t("kycWhyBody")} />

              <Card style={styles.card}>
                <SectionHeading title={t("kycStep1")} icon={Fingerprint} />
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

              <Card style={styles.card}>
                <SectionHeading title={t("kycStep2")} icon={IdCard} />
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
                    {photo ? <Banner icon={CheckCircle2} tint="green" body={t("kycPhotoReady")} /> : null}
                    <Button label={t("kycTakePhoto")} icon={Camera} variant="secondary" loading={busy === "photo"} disabled={!!busy} onPress={() => void takePhoto("camera")} />
                    <Button label={t("evidenceFromLibrary")} icon={Images} variant="tertiary" disabled={!!busy} onPress={() => void takePhoto("library")} />
                    <Button label={t("kycSaveDocument")} loading={busy === "attach"} disabled={!photo || !purpose || !!busy} onPress={() => void attach()} />
                    {!purpose ? <Text style={styles.meta}>{t("kycChooseTypeFirst")}</Text> : null}
                  </>
                ) : null}
              </Card>

              <Card style={styles.card}>
                <SectionHeading title={t("kycStep3")} icon={UserRound} />
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
      <BrandArt name="tribal_divider" width={240} opacity={0.5} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: space.x2 },
  flexTight: { flex: 1, gap: 2 },
  progressBlock: { gap: space.x2, paddingBottom: space.x3, borderBottomWidth: 1, borderBottomColor: colors.neutral200 },
  metaLabel: { ...type.meta, color: colors.neutral600, fontWeight: "600" },
  progressTitle: { ...type.label, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.neutral200, overflow: "hidden" },
  fill: { height: 8, borderRadius: 4, backgroundColor: colors.blue600 },
  reqList: { gap: space.x3 },
  reqRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  reqTitle: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  ok: { ...type.meta, color: colors.successText, fontWeight: "600" },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  metaBlock: { gap: space.x1, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  warn: { ...type.meta, color: colors.warningText },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
