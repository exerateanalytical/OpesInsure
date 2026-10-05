import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Calendar, Camera, CloudUpload, FileText, FileUp, Images, Info, MapPin, Save, Trash2, Video } from "lucide-react-native";
import { Button, Card, ripple, Screen } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { PolicyChoiceCard } from "@/components/claims/PolicyChoiceCard";
import { evidenceIcon, formatBytes } from "@/components/claims/claimProduct";
import { MAX_VIDEO_SECONDS, pickEvidence, type EvidenceSource, type PickedEvidence } from "@/components/claims/evidencePickers";
import { ReviewDocuments, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { CustomerApi, uploadEvidenceFile, type ClaimDraft } from "@/api/customer";
import { clientStateOf } from "@/lib/claimDraft";
import { withDraftEvidence, withoutDraftEvidence, type DraftEvidence } from "@/lib/evidenceUpload";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * New claim, step 3 of 4 (design 32): photos, a short video or documents for the claim being prepared.
 * Each file is uploaded once (documents API, or a chunked resumable upload for video) and its reference
 * is saved on the claim draft (PATCH drafts/{id} evidence); the server attaches them to the claim when
 * the draft is submitted in step 4. Files can be removed from the draft before then.
 */
export default function NewClaimEvidence() {
  const { draftId, from } = useLocalSearchParams<{ draftId: string; from?: string }>();
  const id = typeof draftId === "string" ? draftId : "";
  const { t, td, date } = useTranslation();
  const draft = useLoad<ClaimDraft>(() => CustomerApi.claimDraft(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [pending, setPending] = useState<PickedEvidence | null>(null);
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const files = (draft.data?.payload?.evidence ?? []) as DraftEvidence[];
  const policy = policies.find((p) => p.id === draft.data?.policy_id) ?? draft.data?.policy ?? null;

  const saveEvidence = async (next: DraftEvidence[], step: "evidence" | "review" = "evidence") => {
    const form = clientStateOf(draft.data).form;
    const saved = await CustomerApi.updateClaimDraft(id, { evidence: next, client_state: { ...(form ? { form } : {}), step } });
    draft.setData(saved);
    return saved;
  };

  const pick = async (source: EvidenceSource) => {
    setError(null);
    setNotice(null);
    const picked = await pickEvidence(source);
    if (picked === "CAMERA_DENIED") return setNotice(t("cameraPermissionNeeded"));
    if (picked) {
      setPending(picked);
      setPickerOpen(false);
    }
  };

  const upload = async () => {
    if (!pending || busy) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    setProgress(0);
    try {
      const ref = await uploadEvidenceFile({ ...pending.asset, name: pending.name, size: pending.size }, setProgress);
      await saveEvidence(
        withDraftEvidence(files, {
          ...("document_id" in ref ? { document_id: ref.document_id } : { upload_session_id: ref.upload_session_id }),
          evidence_type: pending.kind,
          name: pending.name,
          mime_type: ref.mime_type,
          size_bytes: ref.size_bytes,
        }),
      );
      setPending(null);
      setNotice(t("claimDraftFileAdded"));
    } catch (e) {
      // The picked file stays on screen so the customer can retry.
      setError(e);
    } finally {
      setBusy(false);
      setProgress(null);
    }
  };

  const remove = async (key: string) => {
    setBusy(true);
    setError(null);
    try {
      await saveEvidence(withoutDraftEvidence(files, key));
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const leave = async (to: "review" | "claims") => {
    setBusy(true);
    setError(null);
    try {
      // Remember where the customer was, so "Resume" reopens this step or the review.
      await saveEvidence(files, to === "review" ? "review" : "evidence");
      if (to === "review" && from === "review" && router.canGoBack()) router.back();
      else if (to === "review") router.push({ pathname: "/claim/new/review" as never, params: { draftId: id } });
      else router.replace({ pathname: "/(customer)/(tabs)/claims" as never, params: { draftSaved: "1" } });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const pickers: { label: string; icon: typeof Camera; source: EvidenceSource }[] = [
    { label: t("evidenceTakePhoto"), icon: Camera, source: "photo" },
    { label: t("evidenceRecordVideo"), icon: Video, source: "video" },
    { label: t("evidenceFromLibrary"), icon: Images, source: "library" },
    { label: t("evidenceChooseDocument"), icon: FileUp, source: "document" },
  ];

  return (
    <Screen
      footer={
        <CtaBar>
          <Button label={t("continue")} icon={ArrowRight} loading={busy && !pending} disabled={busy || !!pending || !draft.data} onPress={() => void leave("review")} />
          <Button label={t("claimSaveDraft")} icon={Save} variant="tertiary" disabled={busy || !draft.data} onPress={() => void leave("claims")} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("claimEvidenceTitle")} subtitle={t("claimEvidenceSubtitle")} right="help" />
      <ClaimWizardSteps current={2} />
      <StatePanel {...draft} onRetry={draft.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(d) => {
          const p = d.payload ?? {};
          const kind = p.incident_type ?? null;
          return (
            <View style={styles.summary}>
              {policy ? <PolicyChoiceCard policy={policy} logoUrl={logoFor(policy)} plain /> : null}
              <View style={styles.facts}>
                {p.incident_at ? <Fact icon={Calendar} value={date(p.incident_at, true)} label={t("claimIncidentDate")} /> : null}
                {kind ? <Fact icon={FileText} value={td(`incidentKind_${kind}`, kind)} label={t("claimIncidentType")} /> : null}
                {p.incident_location ? <Fact icon={MapPin} value={p.incident_location} label={t("claimIncidentLocation")} /> : null}
              </View>
            </View>
          );
        }}
      </StatePanel>

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
      </Pressable>
      {pickerOpen ? (
        <View style={styles.pickers}>
          {pickers.map(({ label, icon: Icon, source }) => (
            <Pressable
              key={source}
              accessibilityRole="button"
              accessibilityLabel={label}
              disabled={busy}
              onPress={() => void pick(source)}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.picker, pressed && styles.pressed, busy && styles.disabled]}
            >
              <Icon size={22} color={colors.blue600} />
              <Text style={styles.pickerText} numberOfLines={2}>{label}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
      {pending ? (
        <ReviewSection icon={pending.kind === "VIDEO" ? Video : pending.kind === "DOCUMENT" ? FileText : Camera} title={t("evidenceCheckFile")}>
          <ReviewDocuments
            files={[{ key: pending.asset.uri, name: pending.name || td(`evidence_${pending.kind}`, pending.kind), uri: pending.asset.uri, image: String(pending.asset.mimeType ?? "").startsWith("image/"), meta: formatBytes(pending.size) }]}
          />
          <ReviewRow label={t("evidenceFor")} value={td(`evidence_${pending.kind}`, pending.kind)} />
          <Button label={t("evidenceUploadThis")} icon={CloudUpload} loading={busy} disabled={busy} onPress={() => void upload()} />
          <Button label={t("evidenceChooseAnother")} variant="tertiary" disabled={busy} onPress={() => { setPending(null); setPickerOpen(true); }} />
        </ReviewSection>
      ) : null}
      {progress !== null ? (
        <View style={styles.progress}>
          <Text style={styles.meta}>{t("claimEvidenceUploading", { percent: Math.round(progress * 100) })}</Text>
          <View style={styles.track} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(progress * 100) }}>
            <View style={[styles.bar, { width: `${Math.round(progress * 100)}%` }]} />
          </View>
        </View>
      ) : null}
      {error ? <ErrorCard error={error} fallback={t("evidenceUploadFailed")} /> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}

      <View style={styles.section}>
        <SectionHeading title={t("claimUploadedFiles", { count: files.length })} />
        {files.length ? (
          <Card style={styles.tight}>
            {files.map((f, i) => {
              const key = f.document_id ?? f.upload_session_id ?? String(i);
              const Icon = evidenceIcon({ mime_type: f.mime_type ?? undefined, evidence_type: f.evidence_type });
              return (
                <View key={key} style={[styles.fileRow, i > 0 && styles.border]}>
                  <View style={styles.thumb}>
                    <Icon size={24} color={colors.blue600} />
                  </View>
                  <View style={styles.flex}>
                    <Text style={styles.label} numberOfLines={1}>{f.name || td(`evidence_${f.evidence_type}`, f.evidence_type)}</Text>
                    <Text style={styles.meta}>{[td(`evidence_${f.evidence_type}`, f.evidence_type), formatBytes(f.size_bytes)].filter(Boolean).join(" · ")}</Text>
                  </View>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={t("claimDraftRemoveFile", { name: f.name || td(`evidence_${f.evidence_type}`, f.evidence_type) })}
                    hitSlop={8}
                    disabled={busy}
                    onPress={() => void remove(key)}
                    style={styles.remove}
                  >
                    <Trash2 size={20} color={colors.dangerText} />
                  </Pressable>
                </View>
              );
            })}
          </Card>
        ) : (
          <Text style={styles.meta}>{t("claimDraftNoFiles")}</Text>
        )}
      </View>

      <Banner icon={Info} tint="blue" body={t("claimEvidenceLimits", { seconds: MAX_VIDEO_SECONDS })} />
      <Banner icon={Info} tint="gold" body={t("claimDraftEvidenceNote")} />
    </Screen>
  );
}

function Fact({ icon, value, label }: { icon: typeof Calendar; value: string; label: string }) {
  return (
    <View style={styles.fact}>
      <TintedIcon icon={icon} tint="blue" size={36} />
      <View style={styles.flex}>
        <Text style={styles.factValue}>{value}</Text>
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
  fileRow: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2, minHeight: 52 },
  border: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  thumb: { width: 48, height: 44, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  remove: { width: 44, height: 44, alignItems: "center", justifyContent: "center" },
  label: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
});
