import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { ArrowRight, FileCheck2, ShieldAlert } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { ConsentRow, QuoteSteps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Documents & declaration gate before checkout; the server decides when a proposal is payable. */
export default function Disclosure() {
  const { t } = useTranslation();
  const proposal = useInsurance((s) => s.proposal);
  const [accepted, setAccepted] = useState(false);
  if (!proposal)
    return (
      <Screen>
        <BrandHeader title={t("qtProposal")} />
        <QuoteSteps current={3} />
        <Card>
          <Text style={styles.title}>{t("qtProposalMissing")}</Text>
          <Text style={styles.body}>{t("qtProposalMissingBody")}</Text>
        </Card>
      </Screen>
    );
  const payable = proposal.status === "PAYMENT_PENDING";
  return (
    <Screen
      footer={
        payable ? (
          <CtaBar>
            <Button label={t("qtReviewPay")} icon={ArrowRight} disabled={!accepted} onPress={() => router.push("/checkout")} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("qtDocsDeclaration")} subtitle={t("qtStep4")} />
      <QuoteSteps current={3} />
      <Card>
        <SectionHeading icon={FileCheck2} title={`${t("qtProposal")} ${proposal.proposal_number}`} right={<StatusChip label={proposal.status.replaceAll("_", " ")} tone={payable ? "success" : "warning"} />} />
        <Text style={styles.body}>{payable ? t("qtUnderwritingComplete") : t("qtNotReleased")}</Text>
      </Card>
      {payable ? (
        <Card>
          <Text style={styles.title}>{t("qtYourDeclaration")}</Text>
          <ConsentRow checked={accepted} onPress={() => setAccepted(!accepted)} label={t("qtDeclarationConsent")} />
        </Card>
      ) : (
        <>
          <Banner icon={ShieldAlert} tint="gold" body={t("qtAnswerDisclosureBody")} />
          <Button label={t("qtAnswerDisclosure")} onPress={() => router.push({ pathname: "/quote/questions", params: { proposalId: proposal.id } })} />
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});
