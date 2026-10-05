import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import { ArrowRight, Check, CircleAlert, FileText, Image as ImageIcon, User } from "lucide-react-native";
import { Button, Screen } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { ReviewDocuments, ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { evidenceIcon, formatBytes, insuredLabel, policyLine, policyTitle, productIcon, productTint, providerName } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { CustomerApi, type ClaimDraft, type ClaimDraftEvidenceReport } from "@/api/customer";
import type { Claim } from "@/api/client";
import { draftMissing, wizardRoute } from "@/lib/claimDraft";
import type { DraftEvidence } from "@/lib/evidenceUpload";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * New claim, step 4 of 4 (design 30): review the policy, incident, evidence
 * and contact details saved on the claim draft (each card's Edit reopens its
 * step on the same draft — never a second claim), confirm the declaration and
 * submit. Only Submit files the claim: POST /mobile/claims/drafts/{id}/submit
 * with declaration_confirmed, which also attaches the draft's evidence.
 * "Save as draft" keeps the draft (already saved server-side) and returns to My claims.
 */
export default function NewClaimReview() {
  const { draftId } = useLocalSearchParams<{ draftId: string }>();
  const id = typeof draftId === "string" ? draftId : "";
  const { t, td, date, language, timeZone } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user ?? null);
  const draft = useLoad<ClaimDraft>(() => CustomerApi.claimDraft(id), [id]);
  const { policies } = usePolicies();
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [touched, setTouched] = useState(false);
  // Filed, but some draft files could not be attached: say so before opening the claim.
  const [filed, setFiled] = useState<{ claim: Claim; evidence: ClaimDraftEvidenceReport } | null>(null);
  // Back from an Edit: show what was just saved on the draft.
  const reloadDraft = draft.reload;
  const seen = React.useRef(false);
  useFocusEffect(
    React.useCallback(() => {
      if (seen.current) void reloadDraft();
      seen.current = true;
    }, [reloadDraft]),
  );
  const missing = draftMissing(draft.data);
  const openClaim = (claimId: string) => router.replace({ pathname: "/claim/[id]", params: { id: claimId } });
  const saveDraft = () => router.replace({ pathname: "/(customer)/(tabs)/claims" as never, params: { draftSaved: "1" } });
  const edit = (step: "policy" | "incident" | "evidence") => {
    const route = wizardRoute(step, id, draft.data?.policy_id, true);
    router.push(route as never);
  };

  const submit = async () => {
    setTouched(true);
    if (!agreed || busy || missing) return;
    setBusy(true);
    setError(null);
    try {
      const result = await CustomerApi.submitClaimDraft(id);
      if (result.evidence.failed.length) setFiled(result);
      else openClaim(result.claim.id);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const timeOf = (iso: string) => {
    try {
      return new Intl.DateTimeFormat(language === "fr" ? "fr-FR" : "en-GB", { hour: "2-digit", minute: "2-digit", timeZone }).format(new Date(iso));
    } catch {
      return "";
    }
  };

  if (filed)
    return (
      <Screen>
        <BrandHeader title={t("claimReviewTitle")} subtitle={filed.claim.claim_number} back={false} right={null} />
        <Banner icon={Check} tint="green" title={t("claimDraftSubmitted")} body={t("claimDraftSubmittedBody", { number: filed.claim.claim_number ?? "" })} />
        <Banner
          icon={CircleAlert}
          tint="gold"
          title={t("claimDraftFilesNotAttached", { count: filed.evidence.failed.length })}
          body={filed.evidence.failed.map((f) => f.name || td(`evidence_${f.evidence_type}`, f.evidence_type)).join(", ")}
        />
        <Button label={t("claimDraftAddFilesNow")} icon={ArrowRight} onPress={() => router.replace({ pathname: "/claim/[id]/evidence", params: { id: filed.claim.id } })} />
        <Button label={t("claimDraftOpenClaim")} variant="secondary" onPress={() => openClaim(filed.claim.id)} />
      </Screen>
    );

  return (
    <Screen
      footer={<ReviewFooter label={t("claimSubmitClaim")} icon={ArrowRight} loading={busy} disabled={!draft.data} onConfirm={() => void submit()} onBack={saveDraft} backLabel={t("claimSaveDraft")} />}
    >
      <BrandHeader title={t("claimReviewTitle")} subtitle={t("claimReviewSubtitle")} right="help" />
      <ClaimWizardSteps current={3} />
      <StatePanel {...draft} onRetry={draft.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(d) => {
          const p = d.payload ?? {};
          const policy = policies.find((x) => x.id === d.policy_id) ?? d.policy ?? null;
          const title = policyTitle(policy, t("claimPolicyLabel"));
          const line = policyLine(policy);
          const provider = providerName(policy);
          const asset = insuredLabel(policy);
          const files = (p.evidence ?? []) as DraftEvidence[];
          return (
            <View style={s.stack}>
              <ReviewIntro body={t("claimReviewIntro")} />
              {missing ? <Banner icon={CircleAlert} tint="gold" title={t("claimDraftIncomplete")} body={t("claimDraftIncompleteBody")} onPress={() => edit(missing === "policy" ? "policy" : "incident")} /> : null}
              <ReviewSection icon={productIcon(title, line)} tint={productTint(title, line)} title={t("claimPolicyInformation")} onEdit={() => edit("policy")} editLabel={t("claimEdit")}>
                <ReviewRow first label={t("claimPolicyLabel")} value={title} />
                {provider ? <ReviewRow label={t("insurer")} value={provider} /> : null}
                {asset ? <ReviewRow label={t("claimInsuredItem")} value={asset} /> : null}
                {policy?.policy_number ? <ReviewRow label={t("claimPolicyNumberLabel")} value={policy.policy_number} /> : null}
              </ReviewSection>

              <ReviewSection icon={FileText} tint="gold" title={t("claimIncidentDetails")} onEdit={() => edit("incident")} editLabel={t("claimEdit")}>
                <ReviewRow first label={t("claimIncidentDate")} value={p.incident_at ? date(p.incident_at) : null} />
                {p.incident_at && timeOf(p.incident_at) ? <ReviewRow label={t("claimIncidentTime")} value={timeOf(p.incident_at)} /> : null}
                <ReviewRow label={t("claimIncidentLocation")} value={p.incident_location ?? null} />
                {p.incident_type ? <ReviewRow label={t("claimIncidentType")} value={td(`incidentKind_${p.incident_type}`, p.incident_type)} /> : null}
                <ReviewRow label={t("reviewWhatHappened")} value={p.description ?? null} />
              </ReviewSection>

              <ReviewSection icon={ImageIcon} tint="green" title={t("claimEvidenceSection")} onEdit={() => edit("evidence")} editLabel={t("claimEdit")}>
                <ReviewDocuments
                  empty={t("claimNoFilesYet")}
                  files={files.map((f, i) => ({
                    key: f.document_id ?? f.upload_session_id ?? String(i),
                    name: f.name || td(`evidence_${f.evidence_type}`, f.evidence_type),
                    meta: formatBytes(f.size_bytes) || null,
                    icon: evidenceIcon({ mime_type: f.mime_type ?? undefined, evidence_type: f.evidence_type }),
                  }))}
                />
              </ReviewSection>

              <ReviewSection icon={User} tint="red" title={t("claimContactDetails")} onEdit={() => router.push("/account/profile")} editLabel={t("claimEdit")}>
                <ReviewRow first label={t("fullName")} value={user?.full_name} />
                <ReviewRow label={t("partiesPhone")} value={user?.phone_e164} />
                <ReviewRow label={t("email")} value={user?.email} />
              </ReviewSection>

              <Pressable
                accessibilityRole="checkbox"
                accessibilityState={{ checked: agreed }}
                accessibilityLabel={t("claimDeclaration")}
                onPress={() => setAgreed((x) => !x)}
                style={[s.declaration, touched && !agreed && s.declarationError]}
              >
                <View style={[s.checkbox, agreed && s.checkboxOn]}>{agreed ? <Check size={16} color={colors.white} strokeWidth={3} /> : null}</View>
                <Text style={s.declarationText}>{t("claimDeclaration")}</Text>
              </Pressable>
              {touched && !agreed ? <Text accessibilityRole="alert" style={s.error}>{t("claimDeclarationRequired")}</Text> : null}
              {error ? <ErrorCard error={error} fallback={t("actionFailed")} onRetry={() => void submit()} /> : null}
            </View>
          );
        }}
      </StatePanel>
    </Screen>
  );
}

const s = StyleSheet.create({
  stack: { gap: space.x3 },
  declaration: { flexDirection: "row", gap: space.x3, alignItems: "flex-start", backgroundColor: colors.blue50, borderRadius: radius.card, padding: space.x4, borderWidth: 1, borderColor: colors.blue50 },
  declarationError: { borderColor: colors.danger },
  declarationText: { ...type.body, color: colors.neutral800, flex: 1 },
  checkbox: { width: 26, height: 26, borderRadius: 7, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center", marginTop: 2 },
  checkboxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
});
