import React, { useCallback, useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Clock3, Hourglass, RefreshCcw, XCircle } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, TintedIcon } from "@/components/design";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import type { Quote } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { proposalStatusInfo } from "@/lib/purchase";
import { quoteOutcome, quoteStateKey, quoteTone } from "@/lib/quoteWorkflow";
import { space } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Manual underwriting: a REFERRED quote (or a proposal whose disclosures
 * raised a referral) waits for a human underwriter. The server is re-read
 * every 30 s: once offers exist the customer moves on to them; a declined,
 * cancelled or expired quote stops the polling and shows what to do next.
 */
export default function Referral() {
  const { t, td, language } = useTranslation();
  const { quoteId, proposalId } = useLocalSearchParams<{ quoteId?: string; proposalId?: string }>();
  const loadQuote = useInsurance((s) => s.loadQuote);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [proposalStatus, setProposalStatus] = useState<string | null>(null);
  const outcome = quote ? quoteOutcome(quote) : null;
  const ended = outcome === "DECLINED" || outcome === "CANCELLED" || outcome === "EXPIRED";

  const refresh = useCallback(async () => {
    setChecking(true);
    setError(null);
    try {
      if (proposalId) {
        const p = await loadProposal(proposalId);
        setProposalStatus(p.status);
        if (proposalStatusInfo(p.status).stage !== "review") router.replace({ pathname: "/proposals/[id]", params: { id: p.id } });
      } else if (quoteId) {
        const r = await loadQuote(quoteId);
        setQuote(r.quote);
        if (r.offers.length && !quoteOutcome(r.quote)) router.replace({ pathname: "/quote/offers", params: { quoteId: r.quote.id } });
      }
    } catch (e) {
      setError(e);
    } finally {
      setChecking(false);
    }
  }, [loadProposal, loadQuote, proposalId, quoteId]);

  // Read the current state once on arrival (a decision may already exist), then every 30 s: manual
  // review can take a while and the customer moves on as soon as it is priced.
  useEffect(() => {
    void refresh();
  }, [refresh]);
  useEffect(() => {
    if ((!quoteId && !proposalId) || ended) return;
    const timer = setInterval(() => void refresh(), 30000);
    return () => clearInterval(timer);
  }, [refresh, quoteId, proposalId, ended]);

  const chip = proposalId
    ? proposalStatus
      ? { label: proposalStatusInfo(proposalStatus, language).label, tone: proposalStatusInfo(proposalStatus, language).tone }
      : { label: td("quoteLifecycle_REFERRED", "Referred"), tone: "warning" as const }
    : quote
      ? { label: td(quoteStateKey(quote), quote.status), tone: quoteTone(quote) }
      : { label: td("quoteLifecycle_REFERRED", "Referred"), tone: "warning" as const };

  return (
    <Screen
      footer={
        <CtaBar>
          {(quoteId || proposalId) && !ended ? <Button label={t("qtCheckStatus")} icon={RefreshCcw} loading={checking} onPress={() => void refresh()} /> : null}
          {ended ? <Button label={t("qwNewQuote")} icon={RefreshCcw} onPress={() => router.replace("/quote/product")} /> : null}
          {quoteId ? (
            <Button label={t("quotesViewQuote")} variant="secondary" onPress={() => router.replace({ pathname: "/quotes/[id]", params: { id: quoteId } })} />
          ) : proposalId ? (
            <Button label={t("coOpenApplication")} variant="secondary" onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id: proposalId } })} />
          ) : (
            <Button label={t("myApplications")} variant="secondary" onPress={() => router.replace("/proposals")} />
          )}
          <Button label={t("qtReturnHome")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)")} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("qtUnderwritingReview")} subtitle={t("qtPersonChecking")} />
      <QuoteSteps current={proposalId ? 3 : 2} />
      <Card>
        <View style={st.head}>
          <TintedIcon icon={ended ? XCircle : Clock3} tint={ended ? "red" : "gold"} size={56} />
          <View style={st.flex}>
            <StatusChip label={chip.label} tone={chip.tone} />
            <Text style={[ps.title, st.title]}>{ended ? t(`rfOutcome_${outcome}` as "rfOutcome_DECLINED") : t("qtManualUnderwriting")}</Text>
          </View>
        </View>
        <Text style={ps.body}>{ended ? t("rfOutcomeBody") : t("qtReferralBody")}</Text>
      </Card>
      {ended ? null : <Banner icon={Hourglass} tint="blue" body={t("qtExpectedResponse")} />}
      {error ? <ErrorCard error={error} fallback={t("qtStatusNotRefreshed")} /> : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1, gap: space.x2, alignItems: "flex-start" },
  head: { flexDirection: "row", gap: space.x3, alignItems: "center" },
  title: { marginTop: 0 },
});
