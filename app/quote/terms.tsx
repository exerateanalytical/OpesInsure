import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { ArrowRight, FileSignature } from "lucide-react-native";
import { BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen } from "@/components/ui";
import { ConsentRow, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { DisclosureApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** Terms & declarations: the acceptance is recorded server-side (DisclosureApi.acceptTerms) before checkout. */
export default function Terms() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{
    proposalId?: string;
  }>();
  const storeProposalId = useInsurance((s) => s.proposal?.id);
  const proposalId = params.proposalId ?? storeProposalId ?? "";
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
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
          <Button label={t("qtContinuePayment")} icon={ArrowRight} disabled={!accepted || !proposalId} loading={busy} onPress={() => void proceed()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("qtTermsDeclarations")} subtitle={t("qtReviewBeforePayment")} />
      <QuoteSteps current={3} />
      <Card>
        <SectionHeading icon={FileSignature} title={t("qtTermsDeclarations")} />
        <Text style={ps.body}>{t("qtTermsConsent")}</Text>
        <ConsentRow checked={accepted} disabled={busy} onPress={() => setAccepted(!accepted)} label={t("qtAcceptDeclarations")} />
      </Card>
    </Screen>
  );
}

const st = StyleSheet.create({
  error: { ...type.meta, color: colors.dangerText },
});
