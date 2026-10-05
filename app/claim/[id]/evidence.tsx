import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Calendar, Camera, Check, CloudUpload, FileText, FileUp, Images, Info, MapPin, Video } from "lucide-react-native";
import { Button, Card, ripple, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
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
import { MAX_VIDEO_SECONDS, pickEvidence, type EvidenceSource, type PickedEvidence } from "@/components/claims/evidencePickers";
import { ReviewDocuments, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";

/** A picked file waiting for the customer's confirmation before it is uploaded. */
type PendingFile = { asset: PickedEvidence["asset"]; kind: string; name: string; size?: number | null };

/**
 * Claim evidence (design 32/34) for a filed claim. Camera photo / video, gallery and document pickers
 * (pickEvidence) upload through uploadClaimEvidence (documents API for images/PDF, resumable chunks for
 * video) under the selected requirement key. The new-claim wizard's evidence step is app/claim/new/evidence
 * (files kept on the claim draft until it is submitted).
 */
export default function Evidence() {
  const { id, requirement: requirementParam } = useLocalSearchParams<{ id: string; requirement?: string }>();
  const { t, td, date } = useTranslation();
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
  const [pending, setPending] = useState<PendingFile | null>(null);
  const allowed = claim.data ? claimActionAllowed("evidence", claim.data.status) : false;
  const policy = policies.find((p) => p.id === claim.data?.policy_id) ?? claimPolicy(claim.data);
  const list = items.data ?? [];

  // Picking a file shows it first (thumbnail / file row and what it is for); Upload sends it.
  const stage = (asset: PickedEvidence["asset"], kind: string, name?: string | null, size?: number | null) => {
    setError(null);
    setNotice(null);
    setPending({ asset, kind, name: name || td(`evidence_${kind}`, kind), size });
    setPickerOpen(false);
  };
  const confirmPending = async () => {
    if (!pending) return;
    // A failed upload keeps the file on screen so the customer can retry.
    if (await upload(pending.asset, pending.kind)) setPending((p) => (p === pending ? null : p));
  };

  const upload = async (asset: PickedEvidence["asset"], kind: string): Promise<boolean> => {
    if (!id) return false;
    setBusy(true);
    setError(null);
    setNotice(null);
    setProgress(0);
    try {
      const sent = await uploadClaimEvidence(id, asset, requirement ?? kind, setProgress);
      setNotice(t(sent.securityCheck ? "evidenceSecurityCheck" : "evidenceUploaded"));
      await Promise.all([items.reload(), requirements.reload()]);
      return true;
    } catch (e) {
      setError(e instanceof Error && e.message !== "FILE_READ_FAILED" ? e.message : t("evidenceUploadFailed"));
      return false;
    } finally {
      setBusy(false);
      setProgress(null);
    }
  };

  const pick = async (source: EvidenceSource) => {
    const picked = await pickEvidence(source);
    if (picked === "CAMERA_DENIED") return setError(t("cameraPermissionNeeded"));
    if (picked) stage(picked.asset, picked.kind, picked.name, picked.size);
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
    { label: t("evidenceTakePhoto"), icon: Camera, run: () => pick("photo") },
    { label: t("evidenceRecordVideo"), icon: Video, run: () => pick("video") },
    { label: t("evidenceFromLibrary"), icon: Images, run: () => pick("library") },
    { label: t("evidenceChooseDocument"), icon: FileUp, run: () => pick("document") },
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
    <Screen>
      <BrandHeader title={t("evidenceTitle")} subtitle={claim.data?.claim_number} right="help" />
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
              {pending ? (
                <ReviewSection icon={pending.kind === "VIDEO" ? Video : pending.kind === "DOCUMENT" ? FileText : Camera} title={t("evidenceCheckFile")}>
                  <ReviewDocuments
                    files={[{ key: pending.asset.uri, name: pending.name, uri: pending.asset.uri, image: String(pending.asset.mimeType ?? "").startsWith("image/"), meta: formatBytes(pending.size) }]}
                  />
                  <ReviewRow label={t("evidenceFor")} value={requirement ? td(`evidence_${requirement}`, requirements.data?.find((r) => r.key === requirement)?.label ?? requirement) : td(`evidence_${pending.kind}`, pending.kind)} />
                  <Button label={t("evidenceUploadThis")} icon={CloudUpload} loading={busy} disabled={busy} onPress={() => void confirmPending()} />
                  <Button label={t("evidenceChooseAnother")} variant="tertiary" disabled={busy} onPress={() => { setPending(null); setPickerOpen(true); }} />
                </ReviewSection>
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
                      <Text style={styles.reqLabel}>{td(`evidence_${item.evidence_type}`, item.evidence_type)}</Text>
                      <Text style={styles.meta}>
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
      {allowed ? (
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
