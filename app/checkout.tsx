import React, { useCallback, useEffect, useState } from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, BadgeCheck, CheckCircle2, Lock, ShieldAlert, ShieldCheck, Smartphone, UserRound } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { LoadingState } from "@/components/StatePanel";
import { ConsentRow, ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { Network, NetworkTiles } from "@/components/policies/RenewalUi";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { isProviderNotConfigured, proposalStatusInfo } from "@/lib/purchase";
import { proposalQuoteId } from "@/lib/offerChoice";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Step 4: review the selected offer and pay (design 13/53). The proposal is
 * always re-read so price and status are the server's; payment goes through
 * the insurance store (requestPayment: persisted idempotency key per attempt,
 * MTN MoMo / Orange Money) and on to the /payment polling screen.
 */
export default function Checkout() {
  const { proposalId, approved } = useLocalSearchParams<{ proposalId?: string; approved?: string }>();
  const proposal = useInsurance((s) => s.proposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const request = useInsurance((s) => s.requestPayment);
  const busy = useInsurance((s) => s.busy);
  const defaultPhone = useSession((s) => s.bootstrap?.user.phone_e164 ?? "");
  const applicant = useSession((s) => s.bootstrap?.user ?? null);
  const legal = useRuntime((s) => s.bootstrap?.legal);
  const links = legalLinks(legal);
  const f = useFormatters();
  const { t } = useTranslation();
  const [phone, setPhone] = useState(defaultPhone);
  const [provider, setProvider] = useState<Network>("mtn_momo");
  const [confirmDetails, setConfirmDetails] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [payError, setPayError] = useState<unknown>(null);
  const id = proposalId ?? proposal?.id;

  // Always re-read the proposal: the price and status on screen must be the server's current ones.
  const load = useCallback(async () => {
    if (!id) return setLoading(false);
    setLoading(true);
    setLoadError(null);
    try {
      await loadProposal(id);
    } catch (e) {
      setLoadError(e);
    } finally {
      setLoading(false);
    }
  }, [id, loadProposal]);
  useEffect(() => {
    void load();
  }, [load]);

  const open = (url: string) => Linking.openURL(url).catch(() => undefined);

  if (!id)
    return (
      <Screen>
        <BrandHeader title={t("coTitle")} />
        <QuoteSteps current={3} />
        <Card>
          <Text style={ps.title}>{t("coNoApplication")}</Text>
          <Button label={t("myApplications")} variant="secondary" onPress={() => router.replace("/proposals")} />
        </Card>
      </Screen>
    );
  if (loading && !proposal)
    return (
      <Screen>
        <BrandHeader title={t("coTitle")} />
        <QuoteSteps current={3} />
        <LoadingState label={t("coLoading")} />
      </Screen>
    );
  if (!proposal)
    return (
      <Screen>
        <BrandHeader title={t("coTitle")} />
        <QuoteSteps current={3} />
        <ErrorCard error={loadError} fallback={t("coLoadFailed")} onRetry={() => void load()} />
      </Screen>
    );

  const info = proposalStatusInfo(proposal.status, f.language);
  const payable = info.stage === "payable";
  const phoneValid = /^\+237[26]\d{8}$/.test(phone);
  const canPay = payable && phoneValid && confirmDetails && acceptTerms && !busy;
  const total = proposal.terms_snapshot?.total_minor;
  // "Change offer" reopens the offers of the quote this application was made from (not whatever quote is in memory).
  const sourceQuoteId = proposalQuoteId(proposal);
  const ownOffer = selectedOffer && (selectedOffer.id === proposal.quote_offer_id || selectedOffer.id === proposal.terms_snapshot?.offer_id) ? selectedOffer : null;
  const pay = async () => {
    if (busy) return;
    setPayError(null);
    try {
      await request(provider, phone);
      router.replace({ pathname: "/payment", params: { proposalId: proposal.id } });
    } catch (e) {
      setPayError(e);
    }
  };

  return (
    <Screen
      footer={
        payable ? (
          <CtaBar>
            <Button label={t("rrPay", { amount: f.xaf(total) })} icon={ArrowRight} loading={busy} disabled={!canPay} onPress={() => void pay()} />
            {sourceQuoteId ? <Button label={t("rrChangeOffer")} variant="tertiary" disabled={busy} onPress={() => router.replace({ pathname: "/quote/offers", params: { quoteId: sourceQuoteId } })} /> : null}
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("coTitle")} subtitle={t("coSubtitle")} />
      <QuoteSteps current={3} />
      {approved === "1" && payable ? <Banner icon={CheckCircle2} tint="green" title={t("payApprovedTitle")} body={t("payApprovedLetsPay")} /> : null}
      {loadError ? <ErrorCard error={loadError} fallback={t("coStaleTerms")} onRetry={() => void load()} /> : null}
      <ProposalSummary proposal={proposal} offer={ownOffer} chip={<StatusChip label={payable ? t("roSelected") : info.label} tone={payable ? "success" : info.tone} />} />
      {!payable ? (
        <Card>
          <SectionHeading title={t("prStatus")} right={<StatusChip label={info.label} tone={info.tone} />} />
          <Text style={ps.body}>{info.message}</Text>
          <Button label={t("coOpenApplication")} onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id: proposal.id } })} />
        </Card>
      ) : (
        <>
          {applicant ? (
            <ReviewSection icon={UserRound} title={t("coApplicant")} onEdit={() => router.push("/account/profile")} editLabel={t("coEdit")}>
              <ReviewRow first label={t("fullName")} value={applicant.full_name} />
              {applicant.email ? <ReviewRow label={t("email")} value={applicant.email} /> : null}
              {applicant.phone_e164 ? <ReviewRow label={t("partiesPhone")} value={applicant.phone_e164} /> : null}
            </ReviewSection>
          ) : null}
          <Card>
            <SectionHeading
              title={t("rrPaymentMethod")}
              right={
                <View style={st.secure}>
                  <Lock size={14} color={colors.neutral600} />
                  <Text style={[ps.meta, { flexShrink: 1 }]}>{t("rrSecure")}</Text>
                </View>
              }
            />
            <NetworkTiles value={provider} onChange={setProvider} disabled={busy} />
            <TextField label={t("rrMomoNumber")} value={phone} onChangeText={setPhone} keyboardType="phone-pad" editable={!busy} placeholder="+2376XXXXXXXX" error={phone && !phoneValid ? t("coPhoneInvalid") : undefined} />
            <View style={st.pinRow}>
              <Smartphone size={16} color={colors.neutral600} />
              <Text style={[ps.meta, st.flex]}>{t("coPinNote")}</Text>
            </View>
            <Banner icon={Lock} tint="blue" body={t("coEncrypted")} />
          </Card>

          <Card>
            <Text style={st.label}>{t("rrConsentTitle")}</Text>
            <ConsentRow checked={confirmDetails} disabled={busy} onPress={() => setConfirmDetails(!confirmDetails)} label={t("coConsentDetails")} />
            <ConsentRow
              checked={acceptTerms}
              disabled={busy}
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

          {payError ? isProviderNotConfigured(payError) ? <ProviderNotConfigured error={payError} /> : <ErrorCard error={payError} fallback={t("coPayFailed")} onRetry={() => void pay()} /> : null}
          <Banner icon={ShieldAlert} tint="gold" body={t("coActivationNote")} />
          <View style={st.trust} accessibilityRole="summary">
            {([[ShieldCheck, "welcomeLicensed"], [Lock, "welcomeSecurePayments"], [BadgeCheck, "welcomeVerifiedProducts"]] as const).map(([Icon, key]) => (
              <View key={key} style={st.trustItem}>
                <Icon size={20} color={colors.gold600} />
                <Text style={st.trustText}>{t(key)}</Text>
              </View>
            ))}
          </View>
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  label: { ...type.label, color: colors.navy950 },
  link: { ...type.body, color: colors.blue600, textDecorationLine: "underline" },
  secure: { flexDirection: "row", alignItems: "center", gap: 4, flexShrink: 1, maxWidth: 150 },
  pinRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  consentText: { ...type.body, color: colors.neutral700 },
  trust: { flexDirection: "row", justifyContent: "space-between", gap: space.x2 },
  trustItem: { flex: 1, flexDirection: "row", alignItems: "center", gap: 6 },
  trustText: { ...type.meta, color: colors.neutral700, flexShrink: 1 },
});
