import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { DisclosureApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useTranslation } from "@/i18n";
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
  return (
    <Screen>
      <AppHeader
        title={t("qtTermsDeclarations")}
        subtitle={t("qtReviewBeforePayment")}
        back
      />
      <Card>
        <Text>
          {t("qtTermsConsent")}
        </Text>
        <Button
          label={accepted ? t("quoteStatus_ACCEPTED") : t("qtAcceptDeclarations")}
          variant={accepted ? "secondary" : "primary"}
          onPress={() => setAccepted(true)}
        />
      </Card>
      <Button
        label={t("qtContinuePayment")}
        disabled={!accepted || !proposalId}
        loading={busy}
        onPress={async () => {
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
        }}
      />
      {error ? <Text accessibilityRole="alert">{error}</Text> : null}
    </Screen>
  );
}
