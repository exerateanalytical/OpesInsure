import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Calendar, Check, Clock3, FileText, Image as ImageIcon, LucideIcon, Mail, MapPin, Pencil, Phone, Sparkles, User } from "lucide-react-native";
import { Button, ripple, Screen } from "@/components/ui";
import { BrandHeader, CtaBar, TintedIcon, type Tint } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { claimExtra, claimPolicy, evidenceIcon, evidenceIsPdf, insuredLabel, policyLine, policyTitle, productIcon, productTint, providerName } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * New claim, step 4 of 4 (design 30): review the policy, incident, evidence
 * and contact details, confirm the declaration and submit. The claim record
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
  const logoFor = useInsurerLogo();
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
      footer={
        <CtaBar>
          <Button label={t("claimSubmitClaim")} icon={ArrowRight} loading={busy} onPress={() => void submit()} />
          <Pressable accessibilityRole="button" onPress={open} hitSlop={8} style={s.draft}>
            <Text style={s.draftText}>{t("claimSaveDraft")}</Text>
          </Pressable>
        </CtaBar>
      }
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
              <ReviewCard icon={productIcon(title, line)} tint={productTint(title, line)} title={t("claimPolicyInformation")} onEdit={() => router.replace({ pathname: "/claim/new", params: { policyId: c.policy_id } })} editLabel={t("claimStepSelectPolicy")}>
                <Text style={s.value}>{title}</Text>
                {provider ? (
                  <View style={s.line}>
                    <InstitutionMark logoUrl={logoFor(c, policy)} initials={provider.slice(0, 2).toUpperCase()} size={22} />
                    <Text style={s.value}>{provider}</Text>
                  </View>
                ) : null}
                {asset ? <Line icon={productIcon(title, line)} text={asset} /> : null}
                {policy?.policy_number ? <Line icon={FileText} text={t("claimPolicyNo", { number: policy.policy_number })} /> : null}
              </ReviewCard>

              <ReviewCard icon={FileText} tint="gold" title={t("claimIncidentDetails")} onEdit={() => router.push({ pathname: "/claim/[id]/incident", params: { id } })} editLabel={t("claimStepIncident")}>
                <Line icon={Calendar} text={date(c.incident_at)} />
                {timeOf(c.incident_at) ? <Line icon={Clock3} text={timeOf(c.incident_at)} /> : null}
                {c.incident_location ? <Line icon={MapPin} text={c.incident_location} /> : null}
                {incidentType ? <Line icon={Sparkles} text={td(`incidentKind_${incidentType}`, incidentType)} /> : null}
                {c.description ? <Line icon={FileText} text={c.description} /> : null}
              </ReviewCard>

              <ReviewCard icon={ImageIcon} tint="green" title={t("claimEvidenceSection")} onEdit={() => router.push({ pathname: "/claim/[id]/evidence", params: { id, wizard: "1" } })} editLabel={t("claimStepEvidence")}>
                <Text style={s.value}>{files.length ? t("claimFilesUploaded", { count: files.length }) : t("claimNoFilesYet")}</Text>
                {files.length ? (
                  <View style={s.thumbs}>
                    {files.slice(0, 8).map((f) => {
                      const Icon = evidenceIcon(f);
                      const pdf = evidenceIsPdf(f);
                      return (
                        <View key={f.id} style={[s.thumb, pdf && s.thumbPdf]} accessible accessibilityLabel={td(`evidence_${f.evidence_type}`, f.evidence_type)}>
                          <Icon size={26} color={pdf ? colors.white : colors.blue600} />
                        </View>
                      );
                    })}
                  </View>
                ) : null}
              </ReviewCard>

              <ReviewCard icon={User} tint="red" title={t("claimContactDetails")} onEdit={() => router.push("/account/profile")} editLabel={t("contactDetails")}>
                {user?.full_name ? <Line icon={User} text={user.full_name} /> : null}
                {user?.phone_e164 ? <Line icon={Phone} text={user.phone_e164} /> : null}
                {user?.email ? <Line icon={Mail} text={user.email} /> : null}
              </ReviewCard>

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

function ReviewCard({ icon, tint, title, onEdit, editLabel, children }: { icon: LucideIcon; tint: Tint; title: string; onEdit: () => void; editLabel: string; children: React.ReactNode }) {
  const { t } = useTranslation();
  return (
    <View style={s.card}>
      <TintedIcon icon={icon} tint={tint} size={56} />
      <View style={s.flex}>
        <View style={s.cardHead}>
          <Text accessibilityRole="header" style={[s.cardTitle, s.flex]}>{title}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={`${t("claimStepReview")}: ${editLabel}`} onPress={onEdit} hitSlop={8} android_ripple={ripple()} style={s.edit}>
            <Pencil size={18} color={colors.blue600} />
            <Text style={s.editText}>{t("claimEdit")}</Text>
          </Pressable>
        </View>
        <View style={s.lines}>{children}</View>
      </View>
    </View>
  );
}

function Line({ icon: Icon, text }: { icon: LucideIcon; text: string }) {
  return (
    <View style={s.line}>
      <Icon size={18} color={colors.navy800} />
      <Text style={[s.value, s.flex]}>{text}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  stack: { gap: space.x3 },
  flex: { flex: 1 },
  card: { flexDirection: "row", gap: space.x3, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4 },
  cardHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  edit: { flexDirection: "row", alignItems: "center", gap: 4, minHeight: 32, paddingHorizontal: 4 },
  editText: { ...type.label, color: colors.blue600 },
  lines: { gap: 6, marginTop: 6 },
  line: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  value: { ...type.body, color: colors.navy950 },
  thumbs: { flexDirection: "row", flexWrap: "wrap", gap: space.x2, marginTop: 4 },
  thumb: { width: 64, height: 64, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  thumbPdf: { backgroundColor: colors.danger },
  declaration: { flexDirection: "row", gap: space.x3, alignItems: "flex-start", backgroundColor: colors.blue50, borderRadius: radius.card, padding: space.x4, borderWidth: 1, borderColor: colors.blue50 },
  declarationError: { borderColor: colors.danger },
  declarationText: { ...type.body, color: colors.neutral800, flex: 1 },
  checkbox: { width: 26, height: 26, borderRadius: 7, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center", marginTop: 2 },
  checkboxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
  draft: { alignSelf: "center", minHeight: 40, justifyContent: "center" },
  draftText: { ...type.label, color: colors.blue600, textDecorationLine: "underline" },
});
