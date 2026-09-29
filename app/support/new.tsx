import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet } from "react-native";
import { LifeBuoy, Link2, Paperclip } from "lucide-react-native";
import { ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar } from "@/components/design";
import { CustomerApi } from "@/api/customer";
import { WalletApi } from "@/api/client";
import { SelectField } from "@/components/forms/SelectField";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { radius, space } from "@/theme/tokens";

const CATEGORIES = [
  "GENERAL_SUPPORT",
  "PAYMENT",
  "POLICY_DOCUMENT",
  "CLAIM",
  "DELIVERY",
  "FORMAL_COMPLAINT",
  "REPORT_FRAUD",
  "PRIVACY_REQUEST",
] as const;

/**
 * New support case. Context params link the case to a claim / payment /
 * policy (claimId, paymentId, policyId + reference) and preselect the
 * category. The server has no link columns yet, so the reference is also
 * written into the description where staff will see it.
 */
export default function NewSupport() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{
    category?: string;
    claimId?: string;
    paymentId?: string;
    policyId?: string;
    reference?: string;
    subject?: string;
    body?: string;
  }>();
  const initial =
    CATEGORIES.find((c) => c === params.category) ??
    (params.claimId ? "CLAIM" : params.paymentId ? "PAYMENT" : "GENERAL_SUPPORT");
  const [category, setCategory] = useState<string>(initial);
  const [subject, setSubject] = useState(params.subject ?? (params.reference ? `${params.reference} — ` : ""));
  const [description, setDescription] = useState(params.body ?? "");
  const [busy, setBusy] = useState(false);
  const [policyId, setPolicyId] = useState<string | null>(null);
  const policies = useLoad(() => (params.policyId || params.claimId || params.paymentId ? Promise.resolve([]) : WalletApi.all(2)), []);
  const [error, setError] = useState<string | null>(null);
  // The case is shown read-only for a last check before it is created.
  const [reviewing, setReviewing] = useState(false);
  const linked = params.claimId
    ? t("supportLinkedClaim", { ref: params.reference ?? params.claimId })
    : params.paymentId
      ? t("supportLinkedPayment", { ref: params.reference ?? params.paymentId })
      : params.policyId
        ? t("supportLinkedPolicy", { ref: params.reference ?? params.policyId })
        : null;

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const context = linked ? `\n\n[${linked}]` : "";
      const created = await CustomerApi.createSupportCase({
        category,
        subject: subject.trim(),
        description: `${description.trim()}${context}`,
        ...(params.claimId ? { claim_id: params.claimId } : {}),
        ...(params.paymentId ? { payment_id: params.paymentId } : {}),
        ...(params.policyId ? { policy_id: params.policyId } : policyId ? { policy_id: policyId } : {}),
      });
      router.replace({ pathname: "/support/[id]", params: { id: created.id } });
    } catch (e) {
      // The form keeps its content so the customer can retry.
      setError(e instanceof Error ? e.message : t("supportCreateFailed"));
    } finally {
      setBusy(false);
    }
  };

  const relatedPolicy = policyId ? policies.data?.find((p) => p.id === policyId) : null;
  const submitLabel = category === "FORMAL_COMPLAINT" ? t("supportSubmitComplaint") : t("supportCreate");

  return (
    <Screen
      footer={
        reviewing ? (
          <ReviewFooter label={submitLabel} loading={busy} error={error} onConfirm={() => void submit()} onBack={() => setReviewing(false)} />
        ) : (
          <CtaBar>
            <Button
              label={t("reviewContinue")}
              disabled={subject.trim().length < 4 || description.trim().length < 15}
              onPress={() => {
                setError(null);
                setReviewing(true);
              }}
            />
          </CtaBar>
        )
      }
    >
      <BrandHeader title={t("supportNewTitle")} subtitle={t("supportNeverShare")} back right="help" />
      {linked ? <Banner icon={Link2} tint="blue" body={linked} /> : null}
      {reviewing ? (
        <>
          <ReviewIntro />
          <ReviewSection icon={LifeBuoy} title={t("supportReviewTitle")} onEdit={() => setReviewing(false)}>
            <ReviewRow first label={t("supportCategory")} value={td(`supportCategory_${category}`, category)} />
            {relatedPolicy ? <ReviewRow label={t("supportRelatedPolicy")} value={relatedPolicy.policy_number} /> : null}
            <ReviewRow label={t("supportSubject")} value={subject.trim()} />
            <ReviewRow label={t("supportDescribe")} value={description.trim()} />
          </ReviewSection>
        </>
      ) : (
      <Card style={s.card}>
        <SelectField
          label={t("supportCategory")}
          value={category}
          onChange={(v) => setCategory(v as typeof category)}
          options={CATEGORIES.map((x) => ({ value: x, label: td(`supportCategory_${x}`, x) }))}
        />
        {category === "PRIVACY_REQUEST" ? <StatusChip label={t("privacyRequestNote")} tone="info" /> : null}
        {policies.data?.length ? (
          <SelectField
            label={t("supportRelatedPolicy")}
            value={policyId ?? undefined}
            placeholder={t("mdNotChosen")}
            onChange={(v) => setPolicyId(v || null)}
            options={[{ value: "", label: t("mdNotChosen") }, ...policies.data.map((p) => ({ value: p.id, label: p.policy_number, subtitle: p.product_name ?? undefined }))]}
          />
        ) : null}
        <TextField label={t("supportSubject")} maxLength={200} value={subject} onChangeText={setSubject} hint={t("minChars", { count: 4 })} />
        <TextField
          label={t("supportDescribe")}
          multiline
          value={description}
          onChangeText={setDescription}
          maxLength={10000}
          style={s.area}
          hint={t("minChars", { count: 15 })}
        />
        <Banner icon={Paperclip} tint="neutral" title={t("supportAttach")} body={t("supportAttachAfterBody")} />
      </Card>
      )}
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x4 },
  area: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
});
