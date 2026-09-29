import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import { ArrowRight, Check, CircleAlert, FileText, Headphones, Info, Landmark, MessageSquareText, Upload } from "lucide-react-native";
import { Button, Card, ripple, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, HeroCard, SectionHeading, TintedIcon, type HeroMeta } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewDocuments, ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { claimPolicy, evidenceIcon, evidenceIsPdf, formatBytes, policyLine, policyTitle, productIcon, providerName, requirementMet } from "@/components/claims/claimProduct";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { CustomerApi, uploadClaimEvidence } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { claimActionAllowed, claimStatusKey, claimTone } from "@/lib/claimStatus";
import { withoutRelock } from "@/lib/appLock";
import { colors, radius, space, type } from "@/theme/tokens";

const MAX_COMMENT = 500;

/**
 * Information request (insurance_claim_information_request): the insurer's
 * outstanding evidence requirements (GET /mobile/claims/{id}/evidence-requirements,
 * MISSING or REJECTED) each with its own "Add file" that uploads straight to
 * the claim under that requirement key (uploadClaimEvidence). An optional
 * comment is sent to the claims team as a support case linked to the claim.
 */
export default function ClaimInformationRequest() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const requirements = useLoad(() => CustomerApi.evidenceRequirements(id), [id]);
  const items = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const [uploading, setUploading] = useState<string | null>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const [comment, setComment] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [touched, setTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);
  // What will be sent (files on the claim, anything still missing, the comment) is checked before sending.
  const [reviewing, setReviewing] = useState(false);
  useFocusEffect(
    React.useCallback(() => {
      void requirements.reload();
      void items.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]),
  );

  const files = items.data ?? [];
  const all = requirements.data ?? [];
  const outstanding = all.filter((r) => !requirementMet(r.status, r.key, files) || r.status === "REJECTED");
  const allowed = claim.data ? claimActionAllowed("information", claim.data.status) : false;

  const add = async (key: string) => {
    setError(null);
    setNotice(null);
    const result = await withoutRelock(() =>
      DocumentPicker.getDocumentAsync({ type: ["application/pdf", "image/jpeg", "image/png"], copyToCacheDirectory: true, multiple: false }),
    );
    const asset = result.assets?.[0];
    if (result.canceled || !asset) return;
    setUploading(key);
    setProgress(0);
    try {
      await uploadClaimEvidence(id, asset, key, setProgress);
      setNotice(t("evidenceUploaded"));
      await Promise.all([items.reload(), requirements.reload()]);
    } catch (e) {
      setError(e);
    } finally {
      setUploading(null);
      setProgress(null);
    }
  };

  const review = () => {
    setTouched(true);
    if (!agreed || !claim.data) return;
    setError(null);
    setReviewing(true);
  };

  const submit = async () => {
    setTouched(true);
    if (!agreed || !claim.data) return;
    setBusy(true);
    setError(null);
    try {
      if (comment.trim())
        await CustomerApi.createSupportCase({
          category: "CLAIM",
          subject: t("infoReqCaseSubject", { number: claim.data.claim_number }),
          description: comment.trim(),
          claim_id: claim.data.id,
        });
      router.replace({ pathname: "/claim/[id]", params: { id } });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        allowed && reviewing ? (
          <ReviewFooter label={t("infoReqSubmit")} icon={ArrowRight} loading={busy} onConfirm={() => void submit()} onBack={() => setReviewing(false)} />
        ) : allowed ? (
          <CtaBar>
            <Button label={t("reviewContinue")} icon={ArrowRight} variant="gold" disabled={!!uploading} onPress={review} />
            <Button
              label={t("claimContactClaimsSupport")}
              icon={Headphones}
              variant="secondary"
              onPress={() => router.push({ pathname: "/support/new", params: { claimId: id, reference: claim.data?.claim_number ?? "", category: "CLAIM" } })}
            />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={t("infoReqTitle")} subtitle={t("infoReqSubtitle")} />
      {allowed && reviewing ? (
        <>
          <ReviewIntro body={t("infoReqReviewIntro")} />
          <ReviewSection icon={FileText} title={t("claimUploadedFiles", { count: files.length })} onEdit={() => setReviewing(false)}>
            <ReviewDocuments
              empty={t("claimNoEvidence")}
              files={files.map((f) => ({ key: f.id, name: td(`evidence_${f.evidence_type}`, f.evidence_type), meta: [formatBytes(f.size_bytes), f.submitted_at ? date(f.submitted_at) : null].filter(Boolean).join(" · ") || null, icon: evidenceIcon(f) }))}
            />
          </ReviewSection>
          {outstanding.length ? (
            <ReviewSection icon={CircleAlert} tint="gold" title={t("infoReqStillMissing")} onEdit={() => setReviewing(false)}>
              {outstanding.map((r, i) => (
                <ReviewRow key={r.key} first={i === 0} label={r.label} value={r.status === "REJECTED" ? t("claimRequirementRejected") : r.required ? t("infoReqRequiredChip") : t("mdOptional")} />
              ))}
            </ReviewSection>
          ) : null}
          <ReviewSection icon={MessageSquareText} title={t("infoReqComments")} onEdit={() => setReviewing(false)}>
            <ReviewRow first label={t("infoReqComments")} value={comment.trim()} />
          </ReviewSection>
          {error ? <ErrorCard error={error} fallback={t("actionFailed")} /> : null}
        </>
      ) : (
      <>
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) => {
          const policy = policies.find((p) => p.id === c.policy_id) ?? claimPolicy(c);
          const title = policyTitle(policy, t("claimPolicyLabel"));
          const provider = providerName(policy);
          const meta: HeroMeta[] = [
            ...(provider ? [{ icon: Landmark, label: t("insurer"), value: provider }] : []),
            { icon: CircleAlert, label: t("infoReqStatus"), value: td(claimStatusKey(c.status), c.status), tone: "warning" as const },
          ];
          return (
            <>
              <HeroCard compact
                icon={productIcon(title, policyLine(policy))}
                title={title}
                lines={[c.claim_number]}
                provider={provider}
                providerLogo={logoFor(c, policy)}
                chip={<StatusChip label={td(claimStatusKey(c.status), c.status)} tone={claimTone(c.status)} />}
                meta={meta}
              />
              {allowed ? (
                <Banner icon={Info} tint="gold" title={t("infoReqWhatWeNeed")} body={t("infoReqWhatWeNeedBody")} />
              ) : (
                <Card>
                  <StatusChip label={t("evidenceLocked")} tone="neutral" />
                  <Text style={s.body}>{t("evidenceLockedBody")}</Text>
                </Card>
              )}
            </>
          );
        }}
      </StatePanel>

      <SectionHeading title={t("infoReqRequired")} />
      <StatePanel {...requirements} onRetry={requirements.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {() =>
          outstanding.length ? (
            <View style={s.list}>
              {outstanding.map((r) => (
                <View key={r.key} style={s.req}>
                  <TintedIcon icon={FileText} tint={r.status === "REJECTED" ? "red" : "blue"} size={40} />
                  <View style={s.flex}>
                    <View style={s.reqHead}>
                      <Text style={s.reqTitle}>{r.label}</Text>
                      <StatusChip label={r.status === "REJECTED" ? t("claimRequirementRejected") : r.required ? t("infoReqRequiredChip") : t("mdOptional")} tone={r.required || r.status === "REJECTED" ? "danger" : "neutral"} />
                    </View>
                    {r.guidance ? <Text style={s.meta}>{r.guidance}</Text> : null}
                    {allowed ? (
                      <Button
                        label={uploading === r.key && progress !== null ? t("claimEvidenceUploading", { percent: Math.round(progress * 100) }) : t("infoReqAddFile")}
                        icon={Upload}
                        variant="secondary"
                        loading={uploading === r.key}
                        disabled={!!uploading}
                        onPress={() => void add(r.key)}
                      />
                    ) : null}
                  </View>
                </View>
              ))}
            </View>
          ) : (
            <Card>
              <View style={s.reqHead}>
                <Check size={20} color={colors.successText} />
                <Text style={[s.body, s.flex]}>{t("infoReqNothingOutstanding")}</Text>
              </View>
            </Card>
          )
        }
      </StatePanel>
      {notice ? <Text accessibilityLiveRegion="polite" style={s.notice}>{notice}</Text> : null}

      {allowed ? (
        <>
          <TextField
            label={t("infoReqComments")}
            value={comment}
            onChangeText={(v) => setComment(v.slice(0, MAX_COMMENT))}
            multiline
            style={s.input}
            placeholder={t("infoReqCommentsPlaceholder")}
            hint={`${comment.length}/${MAX_COMMENT}`}
          />
        </>
      ) : null}

      <SectionHeading title={t("claimUploadedFiles", { count: files.length })} action={t("infoReqViewAll")} onAction={() => router.push({ pathname: "/claim/[id]/evidence", params: { id } })} />
      <StatePanel {...items} onRetry={items.reload} emptyTitle={t("claimNoEvidence")} emptyMessage={t("claimNoEvidenceBody")} loadingLabel={t("loading")}>
        {(rows) => (
          <View style={s.files}>
            {rows.slice(0, 4).map((f) => {
              const Icon = evidenceIcon(f);
              const pdf = evidenceIsPdf(f);
              return (
                <View key={f.id} style={s.file}>
                  <Icon size={24} color={pdf ? colors.danger : colors.blue600} />
                  <View style={s.flex}>
                    <Text style={s.fileName}>{td(`evidence_${f.evidence_type}`, f.evidence_type)}</Text>
                    <Text style={s.meta}>{[formatBytes(f.size_bytes), f.submitted_at ? date(f.submitted_at) : null].filter(Boolean).join(" · ")}</Text>
                  </View>
                </View>
              );
            })}
          </View>
        )}
      </StatePanel>

      <Banner icon={Info} tint="blue" title={t("infoReqImportant")} body={t("infoReqImportantBody")} />
      {allowed ? (
        <Pressable
          accessibilityRole="checkbox"
          accessibilityState={{ checked: agreed }}
          accessibilityLabel={t("infoReqConfirm")}
          onPress={() => setAgreed((x) => !x)}
          android_ripple={ripple()}
          style={[s.declaration, touched && !agreed && s.declarationError]}
        >
          <View style={[s.checkbox, agreed && s.checkboxOn]}>{agreed ? <Check size={16} color={colors.white} strokeWidth={3} /> : null}</View>
          <Text style={[s.body, s.flex]}>{t("infoReqConfirm")}</Text>
        </Pressable>
      ) : null}
      {touched && !agreed ? <Text accessibilityRole="alert" style={s.error}>{t("claimDeclarationRequired")}</Text> : null}
      {error ? <ErrorCard error={error} fallback={t("actionFailed")} /> : null}
      </>
      )}
    </Screen>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  list: { gap: space.x3 },
  req: { flexDirection: "row", gap: space.x3, padding: space.x3, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white },
  reqHead: { flexDirection: "row", flexWrap: "wrap", alignItems: "flex-start", columnGap: space.x2, rowGap: 4 },
  reqTitle: { ...type.label, color: colors.navy950, flexGrow: 1, flexShrink: 1, flexBasis: 120 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600, marginBottom: space.x2 },
  input: { minHeight: 110, textAlignVertical: "top", paddingTop: 12 },
  files: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  file: { flexBasis: 150, flexGrow: 1, flexDirection: "row", alignItems: "center", gap: space.x2, padding: space.x3, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white },
  fileName: { ...type.label, color: colors.navy950 },
  declaration: { flexDirection: "row", gap: space.x3, alignItems: "flex-start", padding: space.x3, borderRadius: radius.card, borderWidth: 1, borderColor: "transparent", minHeight: 48 },
  declarationError: { borderColor: colors.danger },
  checkbox: { width: 26, height: 26, borderRadius: 7, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  checkboxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
