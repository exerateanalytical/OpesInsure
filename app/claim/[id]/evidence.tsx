import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import * as DocumentPicker from "expo-document-picker";
import { ArrowRight, Calendar, Camera, Check, CloudUpload, FileText, FileUp, Images, Info, MapPin, Video } from "lucide-react-native";
import { Button, Card, ripple, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { PolicyChoiceCard } from "@/components/claims/PolicyChoiceCard";
import { claimExtra, claimPolicy, evidenceIcon, evidenceIsPdf, formatBytes, requirementMet } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { CustomerApi, uploadClaimEvidence } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { claimActionAllowed } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";

const MAX_VIDEO_SECONDS = 60;

/**
 * Claim evidence (design 32/34): also step 3 of the new-claim wizard when
 * opened with ?wizard=1. Camera photo / video, gallery and document pickers
 * upload through uploadClaimEvidence (documents API for images/PDF, resumable
 * chunks for video) under the selected requirement key.
 */
export default function Evidence() {
  const { id, requirement: requirementParam, wizard } = useLocalSearchParams<{ id: string; requirement?: string; wizard?: string }>();
  const { t, td, date } = useTranslation();
  const inWizard = wizard === "1";
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const items = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const requirements = useLoad(() => CustomerApi.evidenceRequirements(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const [requirement, setRequirement] = useState<string | null>(typeof requirementParam === "string" && requirementParam ? requirementParam : null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const allowed = claim.data ? claimActionAllowed("evidence", claim.data.status) : false;
  const policy = policies.find((p) => p.id === claim.data?.policy_id) ?? claimPolicy(claim.data);
  const list = items.data ?? [];

  const upload = async (asset: { uri: string; mimeType?: string | null }, kind: string) => {
    if (!id) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    setProgress(0);
    try {
      await uploadClaimEvidence(id, asset, requirement ?? kind, setProgress);
      setNotice(t("evidenceUploaded"));
      await Promise.all([items.reload(), requirements.reload()]);
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

  const pickers: { label: string; icon: typeof Camera; run: () => Promise<void> }[] = [
    { label: t("evidenceTakePhoto"), icon: Camera, run: () => capture("images") },
    { label: t("evidenceRecordVideo"), icon: Video, run: () => capture("videos") },
    { label: t("evidenceFromLibrary"), icon: Images, run: library },
    { label: t("evidenceChooseDocument"), icon: FileUp, run: document },
  ];

  const summary = claim.data ? (
    <View style={styles.summary}>
      {policy ? <PolicyChoiceCard policy={policy} logoUrl={logoFor(claim.data, policy)} plain /> : null}
      <View style={styles.facts}>
        <Fact icon={Calendar} value={date(claim.data.incident_at, true)} label={t("claimIncidentDate")} />
        {claimExtra(claim.data, "incident_type") ? (
          <Fact icon={FileText} value={td(`incidentKind_${claimExtra(claim.data, "incident_type")}`, claimExtra(claim.data, "incident_type") ?? "")} label={t("claimIncidentType")} />
        ) : null}
        {claim.data.incident_location ? <Fact icon={MapPin} value={claim.data.incident_location} label={t("claimIncidentLocation")} /> : null}
      </View>
    </View>
  ) : null;

  return (
    <Screen
      footer={
        inWizard ? (
          <CtaBar>
            <Button label={t("continue")} icon={ArrowRight} disabled={busy} onPress={() => router.push({ pathname: "/claim/new/review" as never, params: { id } })} />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={inWizard ? t("claimEvidenceTitle") : t("evidenceTitle")} subtitle={inWizard ? t("claimEvidenceSubtitle") : claim.data?.claim_number} right="help" />
      {inWizard ? <ClaimWizardSteps current={2} /> : null}
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {() =>
          allowed ? (
            <>
              {summary}
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t("claimTapToAdd")}
                accessibilityState={{ expanded: pickerOpen, busy }}
                disabled={busy}
                onPress={() => setPickerOpen((x) => !x)}
                android_ripple={ripple()}
                style={({ pressed }) => [styles.dropZone, pressed && styles.pressed]}
              >
                <CloudUpload size={40} color={colors.blue600} strokeWidth={1.6} />
                <Text style={styles.dropTitle}>{t("claimTapToAdd")}</Text>
                <View style={styles.chooseBtn}>
                  <Text style={styles.chooseText}>{t("claimChooseFiles")}</Text>
                </View>
                <Text style={styles.dropMeta}>{t("claimFileKinds")}</Text>
                {requirement ? <StatusChip label={t("evidenceForRequest", { name: td(`evidence_${requirement}`, requirement) })} tone="warning" /> : null}
              </Pressable>
              {pickerOpen ? (
                <View style={styles.pickers}>
                  {pickers.map(({ label, icon: Icon, run }) => (
                    <Pressable
                      key={label}
                      accessibilityRole="button"
                      accessibilityLabel={label}
                      disabled={busy}
                      onPress={() => void run()}
                      android_ripple={ripple()}
                      style={({ pressed }) => [styles.picker, pressed && styles.pressed, busy && styles.disabled]}
                    >
                      <Icon size={22} color={colors.blue600} />
                      <Text style={styles.pickerText} numberOfLines={2}>{label}</Text>
                    </Pressable>
                  ))}
                </View>
              ) : null}
              {progress !== null ? (
                <View style={styles.progress}>
                  <Text style={styles.meta}>{t("claimEvidenceUploading", { percent: Math.round(progress * 100) })}</Text>
                  <View
                    style={styles.track}
                    accessibilityRole="progressbar"
                    accessibilityValue={{ min: 0, max: 100, now: Math.round(progress * 100) }}
                  >
                    <View style={[styles.bar, { width: `${Math.round(progress * 100)}%` }]} />
                  </View>
                </View>
              ) : null}
            </>
          ) : (
            <>
              {summary}
              <Card>
                <StatusChip label={t("evidenceLocked")} tone="neutral" />
                <Text style={styles.body}>{t("evidenceLockedBody")}</Text>
              </Card>
            </>
          )
        }
      </StatePanel>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}

      {requirements.data?.length ? (
        <View style={styles.section}>
          <SectionHeading title={t("claimRequiredEvidence")} />
          <Card style={styles.tight}>
            {requirements.data.map((r, i) => {
              const met = requirementMet(r.status, r.key, list);
              const selected = requirement === r.key;
              return (
                <Pressable
                  key={r.key}
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: met, selected }}
                  accessibilityLabel={`${r.label}${r.guidance ? `. ${r.guidance}` : ""}`}
                  accessibilityHint={allowed ? t("claimUploadNow") : undefined}
                  disabled={!allowed}
                  onPress={() => {
                    setRequirement(selected ? null : r.key);
                    setPickerOpen(true);
                  }}
                  style={[styles.reqRow, i > 0 && styles.reqBorder, selected && styles.reqSelected]}
                >
                  <View style={[styles.checkbox, met && styles.checkboxOn]}>{met ? <Check size={14} color={colors.white} strokeWidth={3} /> : null}</View>
                  <FileText size={20} color={colors.navy800} />
                  <View style={styles.flex}>
                    <Text style={styles.reqLabel}>{r.label}{!r.required ? ` ${t("mdOptional")}` : ""}</Text>
                    {r.guidance ? <Text style={styles.meta}>{r.guidance}</Text> : null}
                    {r.status === "REJECTED" ? <Text style={styles.error}>{t("claimRequirementRejected")}</Text> : null}
                  </View>
                </Pressable>
              );
            })}
          </Card>
        </View>
      ) : null}

      <View style={styles.section}>
        <SectionHeading title={t("claimUploadedFiles", { count: list.length })} />
        <StatePanel
          {...items}
          onRetry={items.reload}
          emptyTitle={t("claimNoEvidence")}
          emptyMessage={t("claimNoEvidenceBody")}
          loadingLabel={t("loading")}
        >
          {(rows) => (
            <Card style={styles.tight}>
              {rows.map((item, i) => {
                const Icon = evidenceIcon(item);
                const pdf = evidenceIsPdf(item);
                const size = formatBytes(item.size_bytes);
                return (
                  <View key={item.id} style={[styles.fileRow, i > 0 && styles.reqBorder]}>
                    <View style={[styles.thumb, pdf && styles.thumbPdf]}>
                      <Icon size={26} color={pdf ? colors.white : colors.blue600} />
                      {pdf ? <Text style={styles.pdfTag}>PDF</Text> : null}
                    </View>
                    <View style={styles.flex}>
                      <Text style={styles.reqLabel} numberOfLines={1}>{td(`evidence_${item.evidence_type}`, item.evidence_type)}</Text>
                      <Text style={styles.meta} numberOfLines={1}>
                        {[size, item.submitted_at ? date(item.submitted_at) : null].filter(Boolean).join(" · ")}
                      </Text>
                    </View>
                    <StatusChip
                      label={td(`evidenceStatus_${item.status}`, item.status)}
                      tone={item.status === "VERIFIED" ? "success" : item.status === "REJECTED" ? "danger" : "info"}
                    />
                  </View>
                );
              })}
            </Card>
          )}
        </StatePanel>
      </View>

      <Banner icon={Info} tint="blue" body={t("claimEvidenceLimits", { seconds: MAX_VIDEO_SECONDS })} />
      {allowed && !inWizard ? (
        <Button label={t("evidenceDeclare")} variant="secondary" loading={busy} onPress={() => void declare()} />
      ) : null}
    </Screen>
  );
}

function Fact({ icon, value, label }: { icon: typeof Calendar; value: string; label: string }) {
  return (
    <View style={styles.fact}>
      <TintedIcon icon={icon} tint="blue" size={36} />
      <View style={styles.flex}>
        <Text style={styles.factValue} numberOfLines={2}>{value}</Text>
        <Text style={styles.meta}>{label}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  section: { gap: space.x3 },
  tight: { gap: 0, paddingVertical: space.x1 },
  summary: { gap: space.x3 },
  facts: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, gap: space.x2 },
  fact: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  factValue: { ...type.label, color: colors.navy950 },
  dropZone: { borderWidth: 1.5, borderStyle: "dashed", borderColor: colors.blue500, backgroundColor: colors.blue50, borderRadius: radius.feature, paddingVertical: space.x6, paddingHorizontal: space.x4, alignItems: "center", gap: space.x2, overflow: "hidden" },
  dropTitle: { ...type.cardTitle, color: colors.navy950 },
  chooseBtn: { minHeight: 44, paddingHorizontal: space.x6, borderRadius: radius.pill, backgroundColor: colors.blue100, justifyContent: "center" },
  chooseText: { ...type.label, color: colors.blue700 },
  dropMeta: { ...type.meta, color: colors.neutral600 },
  pickers: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  picker: { flexBasis: 150, flexGrow: 1, minHeight: 60, flexDirection: "row", alignItems: "center", gap: space.x2, paddingHorizontal: space.x3, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, overflow: "hidden" },
  pickerText: { ...type.label, fontSize: 13, lineHeight: 17, color: colors.navy950, flexShrink: 1 },
  progress: { gap: space.x1 },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.neutral100, overflow: "hidden" },
  bar: { height: 8, backgroundColor: colors.blue600 },
  reqRow: { minHeight: 52, flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2 },
  reqBorder: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  reqSelected: { backgroundColor: colors.blue50, marginHorizontal: -space.x2, paddingHorizontal: space.x2, borderRadius: radius.control },
  checkbox: { width: 24, height: 24, borderRadius: 6, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  checkboxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  reqLabel: { ...type.label, color: colors.navy950 },
  fileRow: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2 },
  thumb: { width: 60, height: 48, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  thumbPdf: { backgroundColor: colors.danger },
  pdfTag: { fontFamily: "Inter_700Bold", fontSize: 9, lineHeight: 11, color: colors.white },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
