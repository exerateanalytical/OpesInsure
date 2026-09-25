import React, { useCallback, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Clock3, RefreshCcw } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { useInsurance } from "@/store/insurance";
import { proposalStatusInfo } from "@/lib/purchase";
import { colors } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Manual underwriting: a REFERRED quote (or a proposal whose disclosures
 * raised a referral) waits for a human underwriter. Refresh re-reads the
 * server; once offers or a decision exist the user is moved on.
 */
export default function Referral() {
  const { t } = useTranslation();
  const { quoteId, proposalId } = useLocalSearchParams<{ quoteId?: string; proposalId?: string }>();
  const loadQuote = useInsurance((s) => s.loadQuote);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [status, setStatus] = useState<string>("REFERRED");

  const refresh = useCallback(async () => {
    setChecking(true);
    setError(null);
    try {
      if (proposalId) {
        const p = await loadProposal(proposalId);
        setStatus(p.status);
        if (proposalStatusInfo(p.status).stage !== "review") router.replace({ pathname: "/proposals/[id]", params: { id: p.id } });
      } else if (quoteId) {
        const r = await loadQuote(quoteId);
        setStatus(r.quote.status);
        if (r.offers.length) router.replace("/quote/offers");
      }
    } catch (e) {
      setError(e);
    } finally {
      setChecking(false);
    }
  }, [loadProposal, loadQuote, proposalId, quoteId]);

  return (
    <Screen>
      <AppHeader title={t("qtUnderwritingReview")} subtitle={t("qtPersonChecking")} back />
      <Card feature>
        <Clock3 size={32} color={colors.blue600} />
        <StatusChip label={status.replaceAll("_", " ")} tone="warning" />
        <Text style={ps.title}>{t("qtManualUnderwriting")}</Text>
        <Text style={ps.body}>
          {t("qtReferralBody")}
        </Text>
        <Text style={ps.meta}>{t("qtExpectedResponse")}</Text>
      </Card>
      {error ? <ErrorCard error={error} fallback={t("qtStatusNotRefreshed")} /> : null}
      {quoteId || proposalId ? <Button label={t("qtCheckStatus")} icon={RefreshCcw} loading={checking} onPress={() => void refresh()} /> : null}
      <Button label={t("myApplications")} variant="secondary" onPress={() => router.replace("/proposals")} />
      <Button label={t("qtReturnHome")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)")} />
    </Screen>
  );
}
