import React, { useCallback, useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { ArrowRight, FileDown, FileSignature, FileText } from "lucide-react-native";
import { BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen } from "@/components/ui";
import { ConsentRow, ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { LoadingState } from "@/components/StatePanel";
import { openDocument, openDocumentUrl } from "@/components/documents/openDocument";
import { DisclosureApi, type Proposal } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { normalizeCoverage } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * Terms & declarations before checkout: the contract summary (premium breakdown, covers, limits,
 * exclusions, dates), the pre-contract documents (quote PDF, insurer wording when published) and an
 * explicit acceptance recorded server-side (POST proposals/{id}/terms -> TERMS_ACCEPTANCE declaration
 * with the terms hash, IP and device).
 */
export default function Terms() {
  const { t, language } = useTranslation();
  const params = useLocalSearchParams<{ proposalId?: string }>();
  const storeProposalId = useInsurance((s) => s.proposal?.id);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const proposalId = params.proposalId ?? storeProposalId ?? "";
  const [proposal, setProposal] = useState<Proposal | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!proposalId) return;
    setLoadError(null);
    try {
      setProposal(await loadProposal(proposalId));
    } catch (e) {
      setLoadError(e);
    }
  }, [proposalId, loadProposal]);
  useEffect(() => {
    void load();
  }, [load]);

  const quoteId = proposal?.offer?.quote?.id ?? proposal?.offer?.quote_id ?? null;
  const wording = normalizeCoverage(proposal?.terms_snapshot?.coverage_snapshot ?? proposal?.offer?.coverage_snapshot, language).documents;

  const proceed = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      await DisclosureApi.acceptTerms(proposalId, true);
      router.push({ pathname: "/checkout", params: { proposalId } });
    } catch {
      setError(t("qtAcceptanceFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        <CtaBar>
          {error ? <Text accessibilityRole="alert" style={st.error}>{error}</Text> : null}
          <Button label={t("qtContinuePayment")} icon={ArrowRight} disabled={!accepted || !proposal} loading={busy} onPress={() => void proceed()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("qtTermsDeclarations")} subtitle={t("qtReviewBeforePayment")} />
      <QuoteSteps current={3} />
      {!proposal && !loadError ? <LoadingState label={t("prLoading")} /> : null}
      {loadError && !proposal ? <ErrorCard error={loadError} fallback={t("prLoadFailed")} onRetry={() => void load()} /> : null}
      {proposal ? (
        <>
          <ProposalSummary proposal={proposal} offer={selectedOffer} title={t("qtContractSummary")} />

          <Card>
            <SectionHeading icon={FileText} title={t("qtPreContractDocs")} />
            <Text style={ps.meta}>{t("qtPreContractDocsBody")}</Text>
            {quoteId ? (
              <Button
                label={t("qtOpenQuotePdf")}
                icon={FileDown}
                variant="secondary"
                onPress={() => openDocument({ kind: "quote", quoteId }, proposal.proposal_number, `quote-${proposal.proposal_number}`)}
              />
            ) : null}
            {wording.map((d) => (
              <Button key={d.url} label={d.label} icon={FileDown} variant="secondary" onPress={() => openDocumentUrl(d.url, d.label)} />
            ))}
            {!wording.length ? <Text style={ps.meta}>{t("qtWordingOnIssue")}</Text> : null}
          </Card>

          <Card>
            <SectionHeading icon={FileSignature} title={t("qtTermsDeclarations")} />
            <Text style={ps.body}>{t("qtTermsConsent")}</Text>
            <ConsentRow checked={accepted} disabled={busy} onPress={() => setAccepted(!accepted)} label={t("qtAcceptContract")} />
          </Card>
        </>
      ) : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  error: { ...type.meta, color: colors.dangerText },
});
