import React, { useMemo, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AlarmClock, ArrowRight, CalendarDays, ChevronRight, CircleCheck, Coins, FilePenLine, Gift, RefreshCcw, Scale, ShieldAlert, ShieldCheck } from "lucide-react-native";
import { Banner, BrandHeader, CheckList, CtaBar, DetailRow, RadioCard, SectionHeading } from "@/components/design";
import { Button, Card, Screen } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { DueChip, InfoBox, RenewalHero, RenewalSteps, useRenewalIdentity } from "@/components/policies/RenewalUi";
import { useRenewal } from "@/hooks/useRenewal";
import { useInsurance } from "@/store/insurance";
import { daysUntil, offerDiscount, renewalPeriod, RenewalFlow, sameCarrierOffer } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

type Option = "same" | "compare" | "update";

/**
 * Step 1 of the renewal journey (design 55). The policy record and the
 * renewal quote (POST /policies/{id}/renewal-quote → {quote, offers}) come
 * from the platform; the quote is pushed into the insurance store
 * (setQuoteResult) so the existing offer → proposal → payment path is reused.
 */
const EMPTY_OFFERS: never[] = [];
export default function Renew() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { id } = useLocalSearchParams<{ id: string }>();
  const { policy, policyState, result, busy, error, loadPolicy, prepare } = useRenewal(id, { autoQuote: true });
  const setQuote = useInsurance((s) => s.setQuoteResult);
  const [option, setOption] = useState<Option>("same");

  const offers = result?.offers ?? EMPTY_OFFERS;
  const offer = useMemo(() => sameCarrierOffer(offers, policy?.carrier_id), [offers, policy?.carrier_id]);
  const identity = useRenewalIdentity(policy, offer, result?.quote.risk_facts);
  const days = daysUntil(policy?.coverage_ends_at);
  const period = renewalPeriod(policy?.coverage_ends_at);
  const discount = offer ? offerDiscount(offer) : null;
  const discountLabel = discount ? discount.label ?? (discount.percent !== null ? t("rnLoyaltyDiscountPct", { percent: discount.percent }) : t("rnLoyaltyDiscount")) : null;

  const go = (pathname: string) => router.push({ pathname, params: { id: id ?? "" } } as never);
  const continueRenewal = () => {
    if (!id || !policy) return;
    if (option === "update") return router.push({ pathname: "/policy/[id]/service", params: { id } });
    if (!result) return void prepare();
    setQuote(result.quote, result.offers);
    RenewalFlow.start(policy, result);
    if (option === "compare") return go("/policy/[id]/renewal-offers");
    RenewalFlow.update({ selectedOfferId: offer?.id ?? null });
    go("/policy/[id]/renewal-quote");
  };
  const needsQuote = option !== "update";
  const ctaLabel = needsQuote && !result && error ? t("rnRetryQuote") : t("rnContinue");

  return (
    <Screen
      footer={
        policyState === "ready" ? (
          <CtaBar>
            <Button label={ctaLabel} icon={needsQuote && !result && error ? RefreshCcw : ArrowRight} loading={busy && needsQuote} disabled={needsQuote && !result && !error} onPress={continueRenewal} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("rnTitle")} subtitle={policyState === "ready" ? t("rnSubtitle") : policy?.policy_number ?? t("renewSubtitleFallback")} right={null} />
      <RenewalSteps current={0} />
      {policyState === "loading" ? (
        <LoadingState label={t("renewLoadingPolicy")} />
      ) : policyState === "missing" ? (
        <Card>
          <Text style={st.title}>{t("renewMissing")}</Text>
          <Text style={st.body}>{t("renewMissingBody")}</Text>
          <Button label={t("retry")} variant="secondary" onPress={() => void loadPolicy()} />
          <Button label={t("renewGoPolicies")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)/policies")} />
        </Card>
      ) : (
        <>
          <RenewalHero
            policy={policy}
            offer={offer}
            riskFacts={result?.quote.risk_facts}
            chip={<DueChip />}
            meta={[
              { icon: CalendarDays, label: t("rnCurrentExpiry"), value: f.date(policy?.coverage_ends_at) },
              {
                icon: AlarmClock,
                label: t("rnDueIn"),
                value: days === null ? "—" : days >= 0 ? t("rnDays", { count: days }) : t("rnExpiredDays", { count: Math.abs(days) }),
                tone: "danger",
              },
            ]}
          />

          <Card>
            <SectionHeading title={t("rnOverview")} />
            <DetailRow icon={ShieldCheck} label={t("rnCoverType")} value={identity.productName ?? identity.title} />
            <DetailRow icon={identity.icon} label={identity.isMotor ? t("rnVehicle") : t("rnInsuredObject")} value={identity.vehicle ?? "—"} />
            <DetailRow
              icon={CalendarDays}
              label={t("rnNewPeriod")}
              valueNode={
                <View style={st.alignEnd}>
                  <Text style={st.valueStrong}>{period ? f.range(period.start, period.end) : "—"}</Text>
                  {period ? <Text style={st.meta}>{t("rnTwelveMonths")}</Text> : null}
                </View>
              }
            />
            <DetailRow
              icon={Coins}
              label={t("rnEstimatedPremium")}
              valueNode={<Text style={[st.valueStrong, st.premium]}>{offer ? f.xaf(offer.total_minor) : busy ? t("rnPremiumPending") : t("rnPremiumUnavailable")}</Text>}
            />
            {discount && discountLabel ? (
              <DetailRow
                icon={Gift}
                label={t("rnNoClaimBonus")}
                valueNode={
                  <View style={st.bonus}>
                    <CircleCheck size={16} color={colors.success} />
                    <Text style={st.bonusText} numberOfLines={2}>{t("rnDiscountApplied", { label: discountLabel })}</Text>
                  </View>
                }
              />
            ) : null}
            <InfoBox title={t("rnSameTitle")}>
              <CheckList
                items={[
                  t("rnSameCover", { product: identity.productName ?? identity.title }),
                  identity.isMotor ? t("rnSameVehicle") : t("rnSameInsured"),
                  identity.provider ? t("rnSameProvider", { provider: identity.provider }) : t("rnSameProviderGeneric"),
                ]}
              />
            </InfoBox>
          </Card>

          {error ? <ErrorCard error={error} fallback={t("renewUnavailable")} onRetry={() => void prepare()} retryLabel={t("rnRetryQuote")} /> : null}
          {result && !offers.length ? (
            <Card>
              <Text style={st.body}>{t("rnNoOffers")}</Text>
              <Button label={t("rnRetryQuote")} variant="secondary" loading={busy} onPress={() => void prepare()} />
            </Card>
          ) : null}

          <Card>
            <SectionHeading title={t("rnOptions")} />
            <RadioCard selected={option === "same"} onPress={() => setOption("same")} icon={RefreshCcw} tint="blue" title={t("rnOptSame")} subtitle={t("rnOptSameSub")} right={<ChevronRight size={20} color={colors.navy900} />} />
            <RadioCard selected={option === "compare"} onPress={() => setOption("compare")} icon={Scale} tint="blue" title={t("rnOptCompare")} subtitle={t("rnOptCompareSub")} right={<ChevronRight size={20} color={colors.navy900} />} />
            <RadioCard selected={option === "update"} onPress={() => setOption("update")} icon={FilePenLine} tint="blue" title={t("rnOptUpdate")} subtitle={t("rnOptUpdateSub")} right={<ChevronRight size={20} color={colors.navy900} />} />
          </Card>

          <Banner icon={ShieldAlert} tint="gold" body={t(identity.isMotor ? "rnLapseWarning" : "rnLapseWarningGeneric")} />
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral500 },
  alignEnd: { alignItems: "flex-end" },
  valueStrong: { ...type.body, fontFamily: "Inter_700Bold", color: colors.navy950, textAlign: "right" },
  premium: { fontSize: 18 },
  bonus: { flexDirection: "row", alignItems: "center", gap: space.x2, backgroundColor: colors.successSoft, borderRadius: 10, paddingHorizontal: space.x2, paddingVertical: 6, maxWidth: "100%" },
  bonusText: { ...type.meta, color: colors.successText, flexShrink: 1 },
});
