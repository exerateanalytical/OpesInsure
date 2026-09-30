import React, { useCallback, useEffect, useState } from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, BadgeCheck, CheckCircle2, ClipboardCheck, FileSignature, Hourglass, Lock, RefreshCcw, ShieldAlert, ShieldCheck, Smartphone, UserRound } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ConsentRow, ErrorCard, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { Network, NetworkTiles } from "@/components/policies/RenewalUi";
import { ProposalLifecycleApi, type ProposalChecklist } from "@/api/workflow";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { isProviderNotConfigured, proposalStatusInfo } from "@/lib/purchase";
import { proposalQuoteId } from "@/lib/offerChoice";
import { useFormatters } from "@/hooks/useFormatters";
import { useProposalQuote } from "@/hooks/useProposalQuote";
import { paidRoute, paymentConflict, paymentState, purchaseRoute, termsAcceptedIn } from "@/lib/paymentRouting";
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
  const storeProposal = useInsurance((s) => s.proposal);
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
  const id = proposalId ?? storeProposal?.id;
  // Only the application this screen is for: another one left in the store must never show (or be paid) here.
  const proposal = storeProposal && storeProposal.id === id ? storeProposal : null;
  const [checklist, setChecklist] = useState<ProposalChecklist | null>(null);
  // The checklist (terms acceptance) could not be read: paying is blocked until it can (never pay unaccepted terms).
  const [checklistFailed, setChecklistFailed] = useState(false);
  const quote = useProposalQuote(proposal);

  // Always re-read the proposal: the price and status on screen must be the server's current ones.
  const load = useCallback(async () => {
    if (!id) return setLoading(false);
    setLoading(true);
    setLoadError(null);
    try {
      await loadProposal(id);
      // Optional: whether the contract terms were accepted, and the cover-term rule for the summary.
      const c = await ProposalLifecycleApi.checklist(id).catch(() => null);
      setChecklist(c);
      setChecklistFailed(!c);
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
        <EmptyState title={t("coNoApplication")} message={t("ctNoApplicationBody")} action={t("myApplications")} onPress={() => router.replace("/proposals")} />
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
  // PAYMENT_PENDING stays after a successful payment until issuance: a SUCCEEDED (or in-flight) payment means
  // "paid, policy being issued", never a second checkout (double charge).
  const paid = paymentState({ payments: proposal.payments });
  const payable = info.stage === "payable" && !paid;
  const phoneValid = /^\+237[26]\d{8}$/.test(phone);
  // Known from the checklist: the contract terms must be accepted on the terms screen before paying.
  const termsAccepted = checklist ? termsAcceptedIn(checklist.declarations) : null;
  // Unknown (checklist failed to load) blocks too: the server refuses an app payment without TERMS_ACCEPTANCE.
  const canPay = payable && termsAccepted === true && phoneValid && confirmDetails && acceptTerms && !busy;
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
      // 409: already paid / a payment still with the operator. Follow that one; never charge twice.
      const conflict = paymentConflict(e);
      if (conflict) return router.replace(paidRoute(proposal.id, conflict) as never);
      if (String((e as { code?: unknown })?.code ?? "").toUpperCase() === "TERMS_NOT_ACCEPTED") return router.replace(purchaseRoute(proposal.id, "terms") as never);
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
      {paid ? (
        <Banner
          icon={paid === "paid" ? CheckCircle2 : Hourglass}
          tint={paid === "paid" ? "green" : "blue"}
          title={t(paid === "paid" ? "payReceivedTitle" : "payInFlightTitle")}
          body={t(paid === "paid" ? "payReceivedBody" : "payInFlightBody")}
        />
      ) : null}
      {payable && termsAccepted === null && checklistFailed && !loading ? (
        <Card>
          <Banner icon={FileSignature} tint="gold" title={t("coTermsUnknownTitle")} body={t("coTermsUnknownBody")} />
          <Button label={t("retry")} icon={RefreshCcw} variant="secondary" onPress={() => void load()} />
          <Button label={t("coReviewTerms")} variant="tertiary" onPress={() => router.replace(purchaseRoute(proposal.id, "terms") as never)} />
        </Card>
      ) : null}
      {payable && termsAccepted === false ? (
        <Banner icon={FileSignature} tint="gold" title={t("ctAcceptFirstTitle")} body={t("ctAcceptFirstBody")} onPress={() => router.replace(purchaseRoute(proposal.id, "terms") as never)} />
      ) : payable && termsAccepted && approved !== "1" ? (
        <Banner icon={CheckCircle2} tint="green" title={t("ctAcceptedTitle")} body={t("ctAcceptedBody")} />
      ) : null}
      {loadError ? <ErrorCard error={loadError} fallback={t("coStaleTerms")} onRetry={() => void load()} /> : null}
      <ProposalSummary proposal={proposal} offer={ownOffer} quote={quote} checklist={checklist} chip={<StatusChip label={payable ? t("roSelected") : info.label} tone={payable ? "success" : info.tone} />} />
      {paid ? (
        <Card>
          <Button label={t(paid === "paid" ? "prTrackIssuance" : "payFollowPayment")} icon={ArrowRight} onPress={() => router.replace(paidRoute(proposal.id, paid) as never)} />
          <Button label={t("coOpenApplication")} variant="tertiary" onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id: proposal.id } })} />
        </Card>
      ) : !payable ? (
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

          <ReviewSection icon={ClipboardCheck} title={t("rrConsentTitle")}>
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
          </ReviewSection>

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
  link: { ...type.body, color: colors.blue600, textDecorationLine: "underline" },
  secure: { flexDirection: "row", alignItems: "center", gap: 4, flexShrink: 1, maxWidth: 150 },
  pinRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  consentText: { ...type.body, color: colors.neutral700 },
  trust: { flexDirection: "row", justifyContent: "space-between", gap: space.x2 },
  trustItem: { flex: 1, flexDirection: "row", alignItems: "center", gap: 6 },
  trustText: { ...type.meta, color: colors.neutral700, flexShrink: 1 },
});
