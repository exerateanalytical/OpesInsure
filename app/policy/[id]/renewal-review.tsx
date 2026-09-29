import React, { useMemo, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, CalendarDays, CheckSquare, CircleCheck, Lock, Smartphone, Square, ShieldAlert } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { InfoBox, Network, NetworkTiles, PriceRow, RenewalHero, RenewalSteps, TotalBand } from "@/components/policies/RenewalUi";
import { useRenewal } from "@/hooks/useRenewal";
import { DisclosureApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { offerDiscount, optionalAddons, renewalPeriod, RenewalFlow } from "@/lib/renewal";
import { isProviderNotConfigured, proposalStatusInfo } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Step 3: review the selected renewal offer and pay (design 53). Payment
 * reuses the checkout path exactly: the offer is accepted and a proposal
 * created through the insurance store (selectOffer), then requestPayment
 * (persisted idempotency key per attempt, MTN MoMo / Orange Money) and the
 * /payment polling screen. A proposal that still needs disclosures or
 * documents continues in the existing application screen instead.
 * There is no promo-code endpoint in the checkout API, so none is shown.
 */
const EMPTY_OFFERS: never[] = [];
export default function RenewalReview() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { id } = useLocalSearchParams<{ id: string }>();
  const { policy, policyState, result, busy: quoting, error: quoteError, loadPolicy, prepare } = useRenewal(id, { autoQuote: true });
  const ctx = RenewalFlow.get(id);
  const storeQuote = useInsurance((s) => s.quote);
  const proposal = useInsurance((s) => s.proposal);
  const busy = useInsurance((s) => s.busy);
  const setQuote = useInsurance((s) => s.setQuoteResult);
  const choose = useInsurance((s) => s.selectOffer);
  const request = useInsurance((s) => s.requestPayment);
  const defaultPhone = useSession((s) => s.bootstrap?.user.phone_e164 ?? "");
  const legal = useRuntime((s) => s.bootstrap?.legal);
  const links = legalLinks(legal);
  const [phone, setPhone] = useState(defaultPhone);
  const [provider, setProvider] = useState<Network>("mtn_momo");
  const [confirmDetails, setConfirmDetails] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [payError, setPayError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const offers = result?.offers ?? EMPTY_OFFERS;
  const offer = useMemo(() => offers.find((o) => o.id === ctx?.selectedOfferId) ?? null, [offers, ctx?.selectedOfferId]);
  const period = renewalPeriod(policy?.coverage_ends_at);
  const discount = offer ? offerDiscount(offer) : null;
  const discountLabel = discount ? discount.label ?? (discount.percent !== null ? t("rnLoyaltyDiscountPct", { percent: discount.percent }) : t("rnLoyaltyDiscount")) : null;
  const addons = useMemo(() => optionalAddons(offer, f.language).filter((a) => ctx?.addons.includes(a.code)), [offer, ctx?.addons, f.language]);
  const phoneValid = /^\+237[26]\d{8}$/.test(phone);
  const canPay = !!offer && phoneValid && confirmDetails && acceptTerms && !busy;

  const open = (url: string) => Linking.openURL(url).catch(() => undefined);

  const pay = async () => {
    if (!offer || !result || busy) return;
    setPayError(null);
    setNotice(null);
    try {
      if (storeQuote?.id !== result.quote.id) setQuote(result.quote, result.offers);
      const linked = proposal && (proposal.quote_offer_id === offer.id || proposal.terms_snapshot?.offer_id === offer.id) ? proposal : null;
      if (!linked) await choose(offer);
      const current = useInsurance.getState().proposal;
      if (!current) throw new Error(t("coLoadFailed"));
      const info = proposalStatusInfo(current.status, f.language);
      if (info.stage !== "payable") {
        setNotice(t("rrNeedsApplication"));
        router.push({ pathname: "/proposals/[id]", params: { id: current.id } });
        return;
      }
      // Same acceptance record as new business (TERMS_ACCEPTANCE declaration with hash, IP, device).
      await DisclosureApi.acceptTerms(current.id, true);
      await request(provider, phone);
      router.replace({ pathname: "/payment", params: { proposalId: current.id } });
    } catch (e) {
      setPayError(e);
    }
  };

  const total = offer?.total_minor ?? null;

  return (
    <Screen
      footer={
        policyState === "ready" && offer ? (
          <CtaBar>
            <Button label={t("rrPay", { amount: f.xaf(total) })} icon={ArrowRight} loading={busy} disabled={!canPay} onPress={() => void pay()} />
            <Button label={t("rrChangeOffer")} variant="tertiary" disabled={busy} onPress={() => router.push({ pathname: "/policy/[id]/renewal-offers", params: { id: id ?? "" } } as never)} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("rrTitle")} subtitle={t("rrSubtitle")} right={null} />
      <RenewalSteps current={2} />
      {policyState === "loading" ? (
        <LoadingState label={t("renewLoadingPolicy")} />
      ) : policyState === "missing" ? (
        <Card>
          <Text style={st.title}>{t("renewMissing")}</Text>
          <Text style={st.body}>{t("renewMissingBody")}</Text>
          <Button label={t("retry")} variant="secondary" onPress={() => void loadPolicy()} />
          <Button label={t("renewGoPolicies")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)/policies")} />
        </Card>
      ) : quoting && !result ? (
        <LoadingState label={t("rqPreparing")} />
      ) : quoteError && !result ? (
        <ErrorCard error={quoteError} fallback={t("renewUnavailable")} onRetry={() => void prepare()} retryLabel={t("rnRetryQuote")} />
      ) : !offer ? (
        <Card>
          <Text style={st.title}>{t("rrNoOffer")}</Text>
          <Button label={t("rnOptCompare")} onPress={() => router.replace({ pathname: "/policy/[id]/renewal-offers", params: { id: id ?? "" } } as never)} />
        </Card>
      ) : (
        <>
          <Card>
            <SectionHeading title={t("rrPolicySummary")} right={<StatusChip label={t("rrReady")} tone="success" />} />
            <RenewalHero policy={policy} offer={offer} riskFacts={result?.quote.risk_facts} />
            <View style={st.periodBox}>
              <CalendarDays size={22} color={colors.navy800} />
              <View style={st.flex}>
                <Text style={st.meta}>{t("rnNewPeriod")}</Text>
                <Text style={st.periodValue}>{period ? f.range(period.start, period.end) : t("sumTwelveMonths")}</Text>
                {period ? <Text style={st.meta}>{t("rnTwelveMonths")}</Text> : null}
              </View>
            </View>
          </Card>

          <Card>
            <SectionHeading title={t("rrPriceBreakdown")} />
            <InfoBox tint="neutral">
              <PriceRow label={t("rqBasePremium")} value={f.xaf(offer.premium_minor)} />
              <PriceRow label={t("rqFeesTaxes")} value={f.xaf((offer.tax_minor ?? 0) + (offer.fee_minor ?? 0))} />
              {discount && discountLabel ? <PriceRow label={discountLabel} value={`-${f.xaf(discount.minor)}`} tone="success" /> : null}
            </InfoBox>
            <TotalBand label={t("rrTotalDue")} value={f.xaf(total)} />
            {addons.length ? (
              <View style={st.addons}>
                <Text style={st.label}>{t("rrAddonsRequested")}</Text>
                {addons.map((a) => (
                  <Text key={a.code} style={st.meta}>• {a.name}{a.premiumMinor ? ` · ${f.xaf(a.premiumMinor)}` : ""}</Text>
                ))}
                <Text style={st.meta}>{t("rqAddonsNote")}</Text>
              </View>
            ) : null}
          </Card>

          <Card>
            <SectionHeading
              title={t("rrPaymentMethod")}
              right={
                <View style={st.secure}>
                  <Lock size={14} color={colors.neutral600} />
                  <Text style={[st.meta, { flexShrink: 1 }]}>{t("rrSecure")}</Text>
                </View>
              }
            />
            <NetworkTiles value={provider} onChange={setProvider} disabled={busy} />
            <TextField label={t("rrMomoNumber")} value={phone} onChangeText={setPhone} keyboardType="phone-pad" editable={!busy} placeholder="+2376XXXXXXXX" error={phone && !phoneValid ? t("coPhoneInvalid") : undefined} />
            <View style={st.pinRow}>
              <Smartphone size={16} color={colors.neutral600} />
              <Text style={[st.meta, st.flex]}>{t("coPinNote")}</Text>
            </View>
          </Card>

          <Card>
            <Text style={st.label}>{t("rrConsentTitle")}</Text>
            <Consent checked={confirmDetails} onPress={() => setConfirmDetails(!confirmDetails)} label={t("rrConsentDetails")} />
            <Consent
              checked={acceptTerms}
              onPress={() => setAcceptTerms(!acceptTerms)}
              label={t("rrConsentTerms")}
              trailing={
                <Text style={st.consentText}>
                  {" "}
                  <Text accessibilityRole="link" style={st.link} onPress={() => void open(links.terms)}>{t("termsOfUse")}</Text> {t("rrAnd")}{" "}
                  <Text accessibilityRole="link" style={st.link} onPress={() => void open(links.privacy)}>{t("privacyPolicy")}</Text>.
                </Text>
              }
            />
          </Card>

          {notice ? (
            <Banner icon={CircleCheck} tint="blue" body={notice} />
          ) : null}
          {payError ? isProviderNotConfigured(payError) ? <ProviderNotConfigured error={payError} /> : <ErrorCard error={payError} fallback={t("coPayFailed")} onRetry={() => void pay()} /> : null}
          <Banner icon={ShieldAlert} tint="gold" body={t("rrActivationNote")} />
        </>
      )}
    </Screen>
  );
}

function Consent({ checked, onPress, label, trailing }: { checked: boolean; onPress: () => void; label: string; trailing?: React.ReactNode }) {
  return (
    <View style={st.consentRow}>
      <Pressable accessibilityRole="checkbox" accessibilityState={{ checked }} accessibilityLabel={label} hitSlop={8} onPress={onPress} style={st.checkbox}>
        {checked ? <CheckSquare size={24} color={colors.blue600} /> : <Square size={24} color={colors.neutral400} />}
      </Pressable>
      <Text style={[st.consentText, st.flex]}>
        {label}
        {trailing}
      </Text>
    </View>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  label: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  link: { ...type.body, color: colors.blue600, textDecorationLine: "underline" },
  periodBox: { flexDirection: "row", alignItems: "center", gap: space.x3, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: space.x3 },
  periodValue: { ...type.body, fontFamily: "Inter_700Bold", color: colors.navy950 },
  addons: { gap: 4 },
  secure: { flexDirection: "row", alignItems: "center", gap: 4, flexShrink: 1, maxWidth: 150 },
  pinRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  consentRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  checkbox: { width: 32, height: 32, alignItems: "center", justifyContent: "center" },
  consentText: { ...type.body, color: colors.neutral700 },
});
