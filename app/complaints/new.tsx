import React, { useState } from "react";
import { StyleSheet } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as Crypto from "expo-crypto";
import { Link2, MessageSquareWarning, ShieldCheck } from "lucide-react-native";
import { ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar } from "@/components/design";
import { SelectField } from "@/components/forms/SelectField";
import { WalletApi } from "@/api/client";
import { ComplaintsApi } from "@/api/customerFlows";
import { COMPLAINT_MIN_CHARS, complaintPayload } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { radius, space } from "@/theme/tokens";

/**
 * SHR-013 — file a formal complaint (POST /mobile/complaints → ComplaintService::submit,
 * channel PORTAL). Opened from Help & support ("Formal complaint"), a policy or a claim
 * (policyId / claimId + reference preselect the subject). One Idempotency-Key per form,
 * reused on retry, so a timeout never files the complaint twice.
 */
export default function NewComplaint() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{ policyId?: string; claimId?: string; reference?: string }>();
  const user = useSession((s) => s.bootstrap?.user);
  const [description, setDescription] = useState("");
  const [contact, setContact] = useState<string>(user?.email ?? user?.phone_e164 ?? "");
  const [policyId, setPolicyId] = useState<string | null>(params.policyId ?? null);
  const [reviewing, setReviewing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [key] = useState(() => Crypto.randomUUID());
  const fixedSubject = !!(params.claimId || params.policyId);
  const policies = useLoad(() => (fixedSubject ? Promise.resolve([]) : WalletApi.all(2)), [fixedSubject]);
  const linked = params.claimId
    ? t("cplLinkedClaim", { ref: params.reference ?? params.claimId })
    : params.policyId
      ? t("cplLinkedPolicy", { ref: params.reference ?? params.policyId })
      : null;
  const relatedPolicy = !fixedSubject && policyId ? policies.data?.find((p) => p.id === policyId) : null;
  const valid = description.trim().length >= COMPLAINT_MIN_CHARS;

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const created = await ComplaintsApi.create(complaintPayload({ description, policyId, claimId: params.claimId, contact }), key);
      router.replace({ pathname: "/complaints/[id]", params: { id: created.id } });
    } catch (e) {
      setError(e instanceof Error ? e.message : t("cplCreateFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        reviewing ? (
          <ReviewFooter label={t("cplSubmit")} loading={busy} error={error} onConfirm={() => void submit()} onBack={() => setReviewing(false)} />
        ) : (
          <CtaBar>
            <Button label={t("reviewContinue")} disabled={!valid} onPress={() => (setError(null), setReviewing(true))} />
          </CtaBar>
        )
      }
    >
      <BrandHeader title={t("cplNewTitle")} subtitle={t("cplNewSubtitle")} back right="help" />
      {linked ? <Banner icon={Link2} tint="blue" body={linked} /> : null}
      {reviewing ? (
        <>
          <ReviewIntro />
          <ReviewSection icon={MessageSquareWarning} title={t("cplReviewTitle")} onEdit={() => setReviewing(false)}>
            {linked ? <ReviewRow first label={t("cplSubject")} value={linked} /> : null}
            {relatedPolicy ? <ReviewRow first label={t("cplSubject")} value={relatedPolicy.policy_number} /> : null}
            <ReviewRow first={!linked && !relatedPolicy} label={t("cplDescribe")} value={description.trim()} />
            <ReviewRow label={t("cplContact")} value={contact.trim() || t("cplContactAccount")} />
          </ReviewSection>
        </>
      ) : (
        <Card style={s.card}>
          {!fixedSubject && policies.data?.length ? (
            <SelectField
              label={t("cplRelatedPolicy")}
              value={policyId ?? undefined}
              placeholder={t("mdNotChosen")}
              onChange={(v) => setPolicyId(v || null)}
              options={[{ value: "", label: t("mdNotChosen") }, ...policies.data.map((p) => ({ value: p.id, label: p.policy_number, subtitle: p.product_name ?? undefined }))]}
            />
          ) : null}
          <TextField
            label={t("cplDescribe")}
            multiline
            value={description}
            onChangeText={setDescription}
            maxLength={10000}
            style={s.area}
            hint={t("minChars", { count: COMPLAINT_MIN_CHARS })}
          />
          <TextField label={t("cplContact")} value={contact} onChangeText={setContact} maxLength={255} autoCapitalize="none" hint={t("cplContactHint")} />
        </Card>
      )}
      <Banner icon={ShieldCheck} tint="neutral" title={t("cplRightsTitle")} body={t("cplRightsBody")} />
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x4 },
  area: { minHeight: 140, textAlignVertical: "top", paddingTop: 12 },
});
