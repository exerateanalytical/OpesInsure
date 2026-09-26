import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, CalendarDays, ChevronRight, Clock3, Coins, FileText, Gift, Info, Scale, ShieldPlus } from "lucide-react-native";
import { BrandHeader, CheckList, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import { Button, Card, ripple, Screen } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { InfoBox, PriceRow, RenewalHero, RenewalSteps, TotalBand, useRenewalIdentity } from "@/components/policies/RenewalUi";
import { useRenewal } from "@/hooks/useRenewal";
import { useInsurance } from "@/store/insurance";
import { offerDiscount, optionalAddons, renewalPeriod, RenewalFlow, sameCarrierOffer } from "@/lib/renewal";
import { validityLeft } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Step 2a: the same-insurer renewal quote (design 50). Prices are the
 * offer's own premium / tax / fee / total; a discount row appears only when
 * the payload carries one; add-ons are the offer's optional coverages.
 */
const EMPTY_OFFERS: never[] = [];
export default function RenewalQuote() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { id } = useLocalSearchParams<{ id: string }>();
  const { policy, policyState, result, busy, error, loadPolicy, prepare } = useRenewal(id, { autoQuote: true });
  const setQuote = useInsurance((s) => s.setQuoteResult);
  const ctx = RenewalFlow.get(id);
  const offers = result?.offers ?? EMPTY_OFFERS;
  const offer = useMemo(() => offers.find((o) => o.id === ctx?.selectedOfferId) ?? sameCarrierOffer(offers, policy?.carrier_id), [offers, ctx?.selectedOfferId, policy?.carrier_id]);
  const identity = useRenewalIdentity(policy, offer, result?.quote.risk_facts);
  const period = renewalPeriod(policy?.coverage_ends_at);
  const discount = offer ? offerDiscount(offer) : null;
  const discountLabel = discount ? discount.label ?? (discount.percent !== null ? t("rnLoyaltyDiscountPct", { percent: discount.percent }) : t("rnLoyaltyDiscount")) : null;
  const addons = useMemo(() => optionalAddons(offer, f.language), [offer, f.language]);
  const [picked, setPicked] = useState<string[]>(ctx?.addons ?? []);
  const validity = validityLeft(result?.quote.expires_at, Date.now(), f.language);
  const validDays = result?.quote.expires_at ? Math.max(0, Math.ceil((Date.parse(result.quote.expires_at) - Date.now()) / 86_400_000)) : null;

  const go = (pathname: string) => router.push({ pathname, params: { id: id ?? "" } } as never);
  const toggle = (code: string) => setPicked((p) => (p.includes(code) ? p.filter((c) => c !== code) : [...p, code]));
  const proceed = (next: "review" | "offers") => {
    if (!policy || !result) return;
    setQuote(result.quote, result.offers);
    RenewalFlow.start(policy, result);
    RenewalFlow.update({ selectedOfferId: next === "review" ? offer?.id ?? null : ctx?.selectedOfferId ?? null, addons: picked });
    go(next === "review" ? "/policy/[id]/renewal-review" : "/policy/[id]/renewal-offers");
  };

  return (
    <Screen
      footer={
        policyState === "ready" && offer ? (
          <CtaBar>
            <Button label={t("rqContinueReview")} icon={ArrowRight} disabled={validity.expired} onPress={() => proceed("review")} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("rqTitle")} subtitle={t("rqSubtitle")} right={null} />
      <RenewalSteps current={1} />
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
          <RenewalHero policy={policy} offer={offer} riskFacts={result?.quote.risk_facts} meta={[{ icon: CalendarDays, label: t("rnCurrentExpiry"), value: f.date(policy?.coverage_ends_at) }, { icon: Clock3, label: t("rnStatus"), value: t("rnDueChip"), tone: "warning" }]} />

          {busy && !result ? <LoadingState label={t("rqPreparing")} /> : null}
          {error ? <ErrorCard error={error} fallback={t("renewUnavailable")} onRetry={() => void prepare()} retryLabel={t("rnRetryQuote")} /> : null}
          {result && !offer ? (
            <Card>
              <Text style={st.body}>{t("rnNoOffers")}</Text>
              <Button label={t("rnRetryQuote")} variant="secondary" loading={busy} onPress={() => void prepare()} />
            </Card>
          ) : null}

          {offer ? (
            <>
              <Card>
                <SectionHeading title={t("rqYourQuote")} />
                <PriceRow icon={CalendarDays} label={t("rnNewPeriod")} value={period ? f.range(period.start, period.end) : "—"} strong sub={period ? t("rnTwelveMonths") : undefined} />
                <PriceRow icon={Coins} label={t("rqBasePremium")} value={f.xaf(offer.premium_minor)} />
                <PriceRow icon={FileText} label={t("rqFeesTaxes")} value={f.xaf((offer.tax_minor ?? 0) + (offer.fee_minor ?? 0))} />
                {discount && discountLabel ? <PriceRow icon={Gift} label={discountLabel} value={`-${f.xaf(discount.minor)}`} tone="success" /> : null}
                <TotalBand label={t("rqTotal")} value={f.xaf(offer.total_minor)} />
                <PriceRow
                  icon={Clock3}
                  label={t("rqValidUntil")}
                  value={`${f.date(result?.quote.expires_at ?? offer.valid_until)}${validDays !== null ? ` ${t("rqDaysLeft", { count: validDays })}` : ""}`}
                  strong
                />
                {validity.expired ? <Text style={st.error}>{t("ofExpiredBody")}</Text> : null}
              </Card>

              <Card>
                <SectionHeading title={t("rqContinueGet")} />
                <InfoBox>
                  <CheckList
                    items={[
                      t("rnSameCover", { product: identity.productName ?? identity.title }),
                      identity.isMotor ? t("rnSameVehicle") : t("rnSameInsured"),
                      identity.provider ? t("rnSameProvider", { provider: identity.provider }) : t("rnSameProviderGeneric"),
                    ]}
                  />
                </InfoBox>
              </Card>

              {addons.length ? (
                <Card>
                  <SectionHeading title={t("rqAddons")} right={<Text style={st.meta}>{t("rqAddonsHint")}</Text>} />
                  {addons.map((a) => {
                    const on = picked.includes(a.code);
                    return (
                      <View key={a.code} style={[st.addon, on && st.addonOn]}>
                        <TintedIcon icon={ShieldPlus} tint="blue" size={44} />
                        <View style={st.flex}>
                          <Text style={st.addonTitle}>{a.name}</Text>
                          <Text style={st.meta} numberOfLines={2}>{a.limitMinor !== null ? t("offerLimit", { amount: f.xaf(a.limitMinor) }) : t("offerAvailable")}</Text>
                        </View>
                        <Text style={st.addonPrice}>{a.premiumMinor ? f.xaf(a.premiumMinor) : t("offerAvailable")}</Text>
                        <Switch accessibilityLabel={a.name} value={on} onValueChange={() => toggle(a.code)} trackColor={{ true: colors.blue600, false: colors.neutral300 }} thumbColor={colors.white} />
                      </View>
                    );
                  })}
                  <View style={st.note}>
                    <Info size={16} color={colors.neutral600} />
                    <Text style={[st.meta, st.flex]}>{t("rqAddonsNote")}</Text>
                  </View>
                </Card>
              ) : null}

              {discount && discountLabel ? (
                <InfoBox tint="green">
                  <View style={st.bonusRow}>
                    <TintedIcon icon={Gift} tint="green" size={40} />
                    <View style={st.flex}>
                      <Text style={st.bonusTitle}>{t("rqBonusTitle")}</Text>
                      <Text style={st.bonusBody}>{t("rqBonusBody", { label: discountLabel })}</Text>
                    </View>
                  </View>
                </InfoBox>
              ) : null}

              <Pressable accessibilityRole="button" onPress={() => proceed("offers")} android_ripple={ripple()} style={({ pressed }) => [st.compare, pressed && st.pressed]}>
                <TintedIcon icon={Scale} tint="blue" size={44} />
                <View style={st.flex}>
                  <Text style={st.addonTitle}>{t("rqCompareOthers")}</Text>
                  <Text style={st.meta}>{t("rqCompareOthersSub", { count: Math.max(0, offers.length - 1) })}</Text>
                </View>
                <ChevronRight size={20} color={colors.navy900} />
              </Pressable>
            </>
          ) : null}
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  addon: { flexDirection: "row", alignItems: "center", gap: space.x2, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: space.x2, backgroundColor: colors.white },
  addonOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  addonTitle: { ...type.label, color: colors.navy950 },
  addonPrice: { ...type.label, color: colors.navy950, fontVariant: ["tabular-nums"] },
  note: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  bonusRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  bonusTitle: { ...type.label, color: colors.successText },
  bonusBody: { ...type.meta, color: colors.neutral700, marginTop: 2 },
  compare: { flexDirection: "row", alignItems: "center", gap: space.x3, borderWidth: 1.5, borderColor: colors.blue600, borderRadius: radius.card, padding: space.x3, backgroundColor: colors.white, overflow: "hidden" },
});
