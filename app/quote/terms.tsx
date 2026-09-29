import React, { useCallback, useEffect, useRef, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { ArrowRight, CheckCircle2, FileDown, FileSignature, FileText, Info } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar } from "@/components/design";
import { Button, Screen } from "@/components/ui";
import { ReviewSection } from "@/components/review/ReviewSummary";
import { ConsentRow, ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { openDocument, openDocumentUrl } from "@/components/documents/openDocument";
import { DisclosureApi, type Proposal } from "@/api/client";
import { ProposalLifecycleApi, type ProposalChecklist } from "@/api/workflow";
import { useInsurance } from "@/store/insurance";
import { useProposalQuote } from "@/hooks/useProposalQuote";
import { useFormatters } from "@/hooks/useFormatters";
import { errorMessage, localized, normalizeCoverage, proposalStatusInfo } from "@/lib/purchase";
import { proposalQuoteId } from "@/lib/offerChoice";
import { afterTermsRoute, isPayable, purchaseRoute, termsAcceptedIn } from "@/lib/paymentRouting";
import { quoteDocumentReady, termsGate } from "@/lib/contractTerms";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * "Your contract" before checkout: the contract summary (insurer, insured,
 * cover period, premium breakdown and payment plan, covers with limits and
 * deductibles, exclusions), the pre-contract documents (quote PDF, insurer
 * wording when published) and an explicit acceptance recorded server-side
 * (POST proposals/{id}/terms -> TERMS_ACCEPTANCE declaration with the terms
 * hash, IP and device). Acceptance is only offered while it means something
 * (payable, or documents complete); otherwise the screen says why and leads
 * back to the application.
 */
export default function Terms() {
  const { t, language } = useTranslation();
  const f = useFormatters();
  // approved=1: forwarded here because the application just became payable.
  const params = useLocalSearchParams<{ proposalId?: string; approved?: string }>();
  const storeProposalId = useInsurance((s) => s.proposal?.id);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const proposalId = params.proposalId ?? storeProposalId ?? "";
  const [proposal, setProposal] = useState<Proposal | null>(null);
  const [checklist, setChecklist] = useState<ProposalChecklist | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [accepted, setAccepted] = useState(false);
  const [acceptedAt, setAcceptedAt] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const submitting = useRef(false);
  const [error, setError] = useState<string | null>(null);
  const quote = useProposalQuote(proposal);

  const load = useCallback(async () => {
    if (!proposalId) return;
    setLoadError(null);
    try {
      // Together, so the acceptance gate never flickers: the checklist (optional) carries the acceptance
      // state, what still blocks submission, the cover-term rule and the declaration wording.
      const [p, c] = await Promise.all([loadProposal(proposalId), ProposalLifecycleApi.checklist(proposalId).catch(() => null)]);
      setChecklist(c);
      setProposal(p);
    } catch (e) {
      setLoadError(e);
    }
  }, [proposalId, loadProposal]);
  useEffect(() => {
    void load();
  }, [load]);

  const proceed = async () => {
    if (submitting.current || !proposal) return;
    submitting.current = true;
    setBusy(true);
    setError(null);
    try {
      const res = await DisclosureApi.acceptTerms(proposal.id, true);
      setAcceptedAt(res?.accepted_at ?? new Date().toISOString());
      // Payable -> checkout; an application the acceptance just submitted for review -> its hub.
      const next = afterTermsRoute(proposalId, res?.status);
      if (next.pathname === "/checkout") router.push(next as never);
      else router.replace(next as never);
    } catch (e) {
      setError(errorMessage(e, t("qtAcceptanceFailed")));
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const header = (
    <>
      <BrandHeader title={t("qtContractSummary")} subtitle={proposal?.proposal_number ?? t("qtReviewBeforePayment")} />
      <QuoteSteps current={3} />
    </>
  );

  if (!proposalId)
    return (
      <Screen>
        {header}
        <EmptyState title={t("coNoApplication")} message={t("ctNoApplicationBody")} action={t("myApplications")} onPress={() => router.replace("/proposals")} />
      </Screen>
    );

  const gate = proposal ? termsGate({ status: proposal.status, policyId: proposal.policy_id, blocking: checklist?.blocking, termsAccepted: !!acceptedAt || termsAcceptedIn(checklist?.declarations) }) : null;
  const info = proposalStatusInfo(proposal?.status, language);
  const quoteId = proposalQuoteId(proposal);
  const pdfReady = quoteDocumentReady(quote?.quote);
  const wording = normalizeCoverage(proposal?.terms_snapshot?.coverage_snapshot ?? proposal?.offer?.coverage_snapshot, language).documents;
  // The wording the server records with the acceptance (declarations catalogue), else the app's own sentence.
  const statement = localized(checklist?.declarations?.find((d) => String(d.code).toUpperCase() === "TERMS_ACCEPTANCE")?.statement, language) || t("qtAcceptContract");
  const openApplication = () => router.replace({ pathname: "/proposals/[id]", params: { id: proposalId } });

  const footer = !gate ? null : gate.mode === "accept" ? (
    <Button
      label={String(proposal?.status).toUpperCase() === "DOCUMENTS_PENDING" ? t("qtAcceptSubmit") : t("qtContinuePayment")}
      icon={ArrowRight}
      disabled={!accepted || busy}
      loading={busy}
      onPress={() => void proceed()}
    />
  ) : gate.mode === "accepted" ? (
    <Button label={t("qtContinuePayment")} icon={ArrowRight} onPress={() => router.push(purchaseRoute(proposalId, "checkout") as never)} />
  ) : (
    <Button label={t("coOpenApplication")} icon={ArrowRight} onPress={openApplication} />
  );

  return (
    <Screen
      footer={
        footer ? (
          <CtaBar>
            {error ? <Text accessibilityRole="alert" style={st.error}>{error}</Text> : null}
            {footer}
          </CtaBar>
        ) : null
      }
    >
      {header}
      {params.approved === "1" && proposal && isPayable(proposal.status, proposal.policy_id) ? <Banner icon={CheckCircle2} tint="green" title={t("payApprovedTitle")} body={t("payApprovedLetsPay")} /> : null}
      {!proposal && !loadError ? <LoadingState label={t("prLoading")} /> : null}
      {loadError && !proposal ? <ErrorCard error={loadError} fallback={t("prLoadFailed")} onRetry={() => void load()} /> : null}
      {proposal && gate ? (
        <>
          {gate.mode === "blocked" ? (
            <Banner icon={Info} tint={gate.reason === "closed" ? "red" : gate.reason === "paid" ? "green" : "gold"} title={info.label} body={t(`ctBlocked_${gate.reason}`)} />
          ) : null}

          <ProposalSummary proposal={proposal} offer={selectedOffer} quote={quote} checklist={checklist} />

          <ReviewSection icon={FileText} title={t("qtPreContractDocs")}>
            <View style={st.stack}>
            <Text style={ps.meta}>{t("qtPreContractDocsBody")}</Text>
            {quoteId && pdfReady !== false ? (
              <Button
                label={t("qtOpenQuotePdf")}
                icon={FileDown}
                variant="secondary"
                onPress={() => openDocument({ kind: "quote", quoteId }, t("qtOpenQuotePdf"), `quote-${quote?.quote.quote_number ?? proposal.proposal_number}`)}
              />
            ) : quoteId ? (
              <Text style={ps.meta}>{t("ctQuotePdfPendingNote")}</Text>
            ) : null}
            {wording.map((d) => (
              <Button key={d.url} label={d.label} icon={FileDown} variant="secondary" onPress={() => openDocumentUrl(d.url, d.label)} />
            ))}
            {!wording.length ? <Text style={ps.meta}>{t("qtWordingOnIssue")}</Text> : null}
            </View>
          </ReviewSection>

          {gate.mode === "accept" ? (
            <ReviewSection icon={FileSignature} title={t("qtTermsDeclarations")}>
              <View style={st.stack}>
                <Text style={ps.body}>{t("qtTermsConsent")}</Text>
                <ConsentRow checked={accepted} disabled={busy} onPress={() => setAccepted(!accepted)} label={statement} />
                <Text style={ps.meta}>{t("ctRecordedNote")}</Text>
              </View>
            </ReviewSection>
          ) : gate.mode === "accepted" ? (
            <Banner icon={CheckCircle2} tint="green" title={t("ctAcceptedTitle")} body={acceptedAt ? t("ctAcceptedOn", { date: f.dateTime(acceptedAt) }) : t("ctAcceptedBody")} />
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  error: { ...type.meta, color: colors.dangerText },
  stack: { gap: space.x3 },
});
