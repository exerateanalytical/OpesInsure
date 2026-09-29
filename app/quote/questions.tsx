import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Alert } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, ShieldCheck } from "lucide-react-native";
import { ReviewFooter, ReviewIntro, SchemaReviewSection } from "@/components/review/ReviewSummary";
import { afterDisclosureRoute } from "@/lib/paymentRouting";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen } from "@/components/ui";
import { ContractField } from "@/components/forms/ContractField";
import { QuoteSteps } from "@/components/purchase/PurchaseUi";
import { DisclosureApi, DisclosureSession } from "@/api/client";
import { useTranslation } from "@/i18n";
import { fieldFacts, isFieldVisible, parseContractField, validateStep, type RiskField } from "@/lib/riskSchema";
import { isAnswersLocked } from "@/lib/quoteWorkflow";

/** Disclosure questions carry InputFieldContract v1 (boolean, pickers, or free_text): one renderer. */
function questionFields(session?: DisclosureSession): RiskField[] {
  return (session?.questions ?? [])
    .map((q) => parseContractField({ ...q, key: q.id, type: q.type ?? "boolean" }))
    .filter((f): f is RiskField => f !== null);
}

export default function Questions() {
  const { t, language } = useTranslation();
  const { proposalId = "" } = useLocalSearchParams<{ proposalId?: string }>();
  const [s, setS] = useState<DisclosureSession>();
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Answers are checked on a read-only review before anything is sent.
  const [reviewing, setReviewing] = useState(false);
  const fields = useMemo(() => questionFields(s), [s]);
  const noCount = fields.filter((f) => f.type === "boolean" && isFieldVisible(f, values) && values[f.key] === "false").length;

  const load = useCallback(async () => {
    if (!proposalId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setFailed(false);
    try {
      const x = await DisclosureApi.session(proposalId);
      setS(x);
      // Booleans default to "No" (as before); other answers start empty.
      setValues(Object.fromEntries(x.questions.map((q) => [q.id, q.answer === undefined || q.answer === null ? ((q.type ?? "boolean") === "boolean" ? "false" : "") : String(q.answer)])));
    } catch {
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }, [proposalId]);
  useEffect(() => {
    void load();
  }, [load]);

  const review = () => {
    const e = validateStep({ key: "disclosure", title: "", fields }, values, language === "fr" ? "fr" : "en");
    setErrors(e);
    if (Object.values(e).some(Boolean)) return;
    setError(null);
    setReviewing(true);
  };

  const submit = async () => {
    if (busy) return;
    const e = validateStep({ key: "disclosure", title: "", fields }, values, language === "fr" ? "fr" : "en");
    setErrors(e);
    if (Object.values(e).some(Boolean)) return setReviewing(false);
    setBusy(true);
    setError(null);
    try {
      const answers: Record<string, unknown> = {};
      for (const f of fields) if (isFieldVisible(f, values)) Object.assign(answers, fieldFacts(f.type === "text" ? { ...f, freeText: f.freeText ?? "UNCLASSIFIED" } : f, values));
      await DisclosureApi.saveAnswers(proposalId, answers as Record<string, boolean | string>);
      const x = await DisclosureApi.submit(proposalId);
      // Straight-through proposals become payable and go straight to payment (terms -> checkout);
      // flagged ones wait for an underwriter; others need documents (the hub routes those).
      router.replace(afterDisclosureRoute(proposalId, x.status) as never);
    } catch (e) {
      // 422 on `status`: the proposal was already submitted, so answers are frozen. Reload the hub, never retry.
      if (isAnswersLocked(e)) {
        Alert.alert(t("disclosureTitle"), t("prAnswersLocked"));
        router.replace({ pathname: "/proposals/[id]", params: { id: proposalId } });
        return;
      }
      setError(t("disclosureSubmitFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        s && reviewing ? (
          <ReviewFooter label={t("discConfirmSubmit")} loading={busy} error={error} onConfirm={() => void submit()} onBack={() => setReviewing(false)} backLabel={t("discChangeAnswers")} />
        ) : s ? (
          <CtaBar>
            <Button label={t("discReviewAnswers")} icon={ArrowRight} disabled={busy} onPress={review} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("disclosureTitle")} subtitle={t("disclosureSubtitle")} />
      <QuoteSteps current={1} />
      {!proposalId ? (
        <EmptyState title={t("disclosureNoProposal")} message={t("disclosureNoProposalBody")} action={t("startQuote")} onPress={() => router.replace("/quote/product")} />
      ) : loading ? (
        <LoadingState label={t("disclosureLoading")} />
      ) : failed ? (
        <ErrorState onRetry={() => void load()} />
      ) : null}
      {fields.length && reviewing ? (
        <>
          <ReviewIntro body={noCount ? t("discReviewIntroNo", { count: noCount }) : t("discReviewIntro")} />
          <SchemaReviewSection icon={ShieldCheck} title={t("disclosureTitle")} fields={fields} values={values} onEdit={() => setReviewing(false)} />
        </>
      ) : fields.length ? (
        <Card>
          <SectionHeading title={t("disclosureTitle")} icon={ShieldCheck} />
          {fields.map((f) =>
            isFieldVisible(f, values) ? (
              <ContractField
                key={f.key}
                field={f}
                value={values[f.key]}
                values={values}
                error={errors[f.key] || undefined}
                onChange={(v) => setValues((x) => ({ ...x, [f.key]: v }))}
                setAny={(k, v) => setValues((x) => ({ ...x, [k]: v }))}
                screen="quote.disclosure"
              />
            ) : null,
          )}
        </Card>
      ) : null}
    </Screen>
  );
}
