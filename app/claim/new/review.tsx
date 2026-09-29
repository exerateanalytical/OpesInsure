import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Check, FileText, Image as ImageIcon, User } from "lucide-react-native";
import { Screen } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { ReviewDocuments, ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { claimExtra, claimPolicy, evidenceIcon, formatBytes, insuredLabel, policyLine, policyTitle, productIcon, productTint, providerName } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * New claim, step 4 of 4 (design 30): review the policy, incident, evidence
 * and contact details (shared review cards, each with Edit back to its step),
 * confirm the declaration and submit. The claim record
 * already exists (created by step 2), so Submit sends the declaration
 * (PUT /mobile/claims/{id}/incident declaration_confirmed) and opens the claim.
 */
export default function NewClaimReview() {
  const { id: raw } = useLocalSearchParams<{ id: string }>();
  const id = typeof raw === "string" ? raw : "";
  const { t, td, date, language, timeZone } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user ?? null);
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const evidence = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const { policies } = usePolicies();
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [touched, setTouched] = useState(false);
  const open = () => router.replace({ pathname: "/claim/[id]", params: { id } });

  const submit = async () => {
    setTouched(true);
    if (!agreed) return;
    setBusy(true);
    setError(null);
    try {
      await ClaimsApi.submitDeclaration(id);
      open();
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

  return (
    <Screen
      footer={<ReviewFooter label={t("claimSubmitClaim")} icon={ArrowRight} loading={busy} onConfirm={() => void submit()} onBack={open} backLabel={t("claimSaveDraft")} />}
    >
      <BrandHeader title={t("claimReviewTitle")} subtitle={t("claimReviewSubtitle")} right="help" />
      <ClaimWizardSteps current={3} />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) => {
          const policy = policies.find((p) => p.id === c.policy_id) ?? claimPolicy(c);
          const title = policyTitle(policy, t("claimPolicyLabel"));
          const line = policyLine(policy);
          const provider = providerName(policy);
          const asset = insuredLabel(policy);
          const incidentType = claimExtra(c, "incident_type");
          const files = evidence.data ?? [];
          return (
            <View style={s.stack}>
              <ReviewIntro body={t("claimReviewIntro")} />
              <ReviewSection icon={productIcon(title, line)} tint={productTint(title, line)} title={t("claimPolicyInformation")} onEdit={() => router.replace({ pathname: "/claim/new", params: { policyId: c.policy_id } })} editLabel={t("claimEdit")}>
                <ReviewRow first label={t("claimPolicyLabel")} value={title} />
                {provider ? <ReviewRow label={t("insurer")} value={provider} /> : null}
                {asset ? <ReviewRow label={t("claimInsuredItem")} value={asset} /> : null}
                {policy?.policy_number ? <ReviewRow label={t("claimPolicyNumberLabel")} value={policy.policy_number} /> : null}
              </ReviewSection>

              <ReviewSection icon={FileText} tint="gold" title={t("claimIncidentDetails")} onEdit={() => router.push({ pathname: "/claim/[id]/incident", params: { id } })} editLabel={t("claimEdit")}>
                <ReviewRow first label={t("claimIncidentDate")} value={date(c.incident_at)} />
                {timeOf(c.incident_at) ? <ReviewRow label={t("claimIncidentTime")} value={timeOf(c.incident_at)} /> : null}
                <ReviewRow label={t("claimIncidentLocation")} value={c.incident_location} />
                {incidentType ? <ReviewRow label={t("claimIncidentType")} value={td(`incidentKind_${incidentType}`, incidentType)} /> : null}
                <ReviewRow label={t("reviewWhatHappened")} value={c.description} />
              </ReviewSection>

              <ReviewSection icon={ImageIcon} tint="green" title={t("claimEvidenceSection")} onEdit={() => router.push({ pathname: "/claim/[id]/evidence", params: { id, wizard: "1" } })} editLabel={t("claimEdit")}>
                <ReviewDocuments
                  empty={t("claimNoFilesYet")}
                  files={files.map((f) => ({ key: f.id, name: td(`evidence_${f.evidence_type}`, f.evidence_type), meta: formatBytes(f.size_bytes) || null, icon: evidenceIcon(f) }))}
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
