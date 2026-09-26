import React, { useCallback, useEffect, useRef, useState } from "react";
import { ActivityIndicator, AppState, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, CalendarDays, Car, Coins, Headset, Lock, RefreshCcw, ShieldAlert, ShieldCheck, Smartphone } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, HeroCard, HeroMeta, SectionHeading, StepIndicator, TintedIcon } from "@/components/design";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { NetworkTiles } from "@/components/policies/RenewalUi";
import { useInsurance } from "@/store/insurance";
import { humanize, isNotFound, isProviderNotConfigured, localized, paymentStatusInfo, providerName, purchaseStep } from "@/lib/purchase";
import { carrierLogo, riskVehicleLabel } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const POLL_MS = 5000;
/** After this long without a decision, suggest leaving and checking later. */
const PATIENCE_MS = 3 * 60 * 1000;

/**
 * Payment processing (design 58). Status is server authoritative: the
 * payment is refreshed (or recovered from secure storage) and the purchase
 * status endpoint decides when to move on to /confirmation. The customer's
 * "I have completed payment" only triggers another verified check.
 */
export default function Payment() {
  const { proposalId } = useLocalSearchParams<{ proposalId?: string }>();
  const payment = useInsurance((s) => s.payment);
  const purchase = useInsurance((s) => s.purchase);
  const proposal = useInsurance((s) => s.proposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const quote = useInsurance((s) => s.quote);
  const recover = useInsurance((s) => s.recoverPayment);
  const refresh = useInsurance((s) => s.refreshPayment);
  const refreshPurchase = useInsurance((s) => s.refreshPurchase);
  const f = useFormatters();
  const { t, td } = useTranslation();
  const STEPS = [t("ppStepReview"), t("ppStepPayment"), t("ppStepConfirmation"), t("ppStepIssued")];
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [startedAt] = useState(() => Date.now());
  const [now, setNow] = useState(() => Date.now());
  const inFlight = useRef(false);

  const check = useCallback(async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    setChecking(true);
    try {
      const current = useInsurance.getState().payment ? await refresh() : await recover();
      // The purchase-status endpoint is authoritative (and settles demo payments).
      const agg = await refreshPurchase().catch(() => null);
      setError(null);
      const status = agg?.status ?? "";
      if (current?.status === "SUCCEEDED" || status === "ISSUANCE_PENDING" || status === "POLICY_ISSUED")
        router.replace({ pathname: "/confirmation", params: { proposalId: current?.proposal_id ?? proposalId ?? "" } });
    } catch (e) {
      setError(e);
    } finally {
      inFlight.current = false;
      setChecking(false);
      setNow(Date.now());
    }
  }, [proposalId, recover, refresh, refreshPurchase]);

  const step = purchaseStep(payment?.status, purchase?.status);
  useEffect(() => {
    void check();
    const sub = AppState.addEventListener("change", (s) => {
      if (s === "active") void check();
    });
    return () => sub.remove();
  }, [check]);
  // A provider-not-configured (422) or not-found (404) answer will not change
  // by polling again: stop and show the message instead of stalling.
  const halted = isProviderNotConfigured(error) || isNotFound(error);
  useEffect(() => {
    if (step.failed || step.done || halted) return;
    const timer = setInterval(() => void check(), POLL_MS);
    return () => clearInterval(timer);
  }, [check, step.failed, step.done, halted]);

  const waitingLong = now - startedAt > PATIENCE_MS && !step.done && !step.failed;
  const pInfo = paymentStatusInfo(payment?.status, f.language);
  const provider = payment ? humanize(payment.provider) : null;
  const checkoutHref = { pathname: "/checkout", params: { proposalId: payment?.proposal_id ?? proposalId ?? "" } } as const;

  // Product hero: what is being paid for, from the proposal / offer already in the store (never recomputed).
  const source = proposal?.offer ?? selectedOffer ?? null;
  const terms = proposal?.terms_snapshot;
  const productName = localized(source?.product?.name, f.language) || t("insurancePolicy");
  const vehicle = riskVehicleLabel(quote?.risk_facts);
  const totalMinor = payment?.amount_minor ?? terms?.total_minor ?? null;
  const meta: HeroMeta[] = [
    ...(vehicle ? [{ icon: Car, label: t("rnVehicle"), value: vehicle } as HeroMeta] : []),
    { icon: CalendarDays, label: t("ppPolicyStarts"), value: terms?.coverage_starts_at ? f.date(terms.coverage_starts_at) : t("sumWhenPaid") },
    { icon: Coins, label: t("ppTotalPayable"), value: totalMinor !== null ? f.xaf(totalMinor) : "—" },
  ];

  return (
    <Screen
      footer={
        <CtaBar>
          {step.failed ? (
            <Button label={t("payTryAgain")} icon={RefreshCcw} onPress={() => router.replace(checkoutHref)} />
          ) : (
            <>
              <Button label={t("ppCompleted")} icon={ArrowRight} loading={checking} onPress={() => void check()} />
              <Button label={t("ppTryAnother")} variant="tertiary" onPress={() => router.replace(checkoutHref)} />
            </>
          )}
          <Button
            label={t("payCheckLater")}
            variant="tertiary"
            onPress={() => {
              const id = payment?.proposal_id ?? proposalId;
              if (id) router.replace({ pathname: "/proposals/[id]", params: { id } });
              else router.replace("/(customer)/(tabs)/policies");
            }}
          />
        </CtaBar>
      }
    >
      <BrandHeader title={t("ppTitle")} subtitle={t("ppStepOf", { current: step.done ? 3 : 2, total: 4 })} right={null} />
      <StepIndicator steps={STEPS} current={step.done ? 2 : 1} />

      <HeroCard
        icon={vehicle ? Car : ShieldCheck}
        title={productName}
        provider={source ? providerName(source, f.language) : proposal ? t("licensedCarrier") : null}
        providerLogo={source ? carrierLogo(source) : null}
        lines={[proposal ? t("sumApplication") + " " + proposal.proposal_number : null]}
        meta={meta}
      />

      {payment ? (
        <Card>
          <SectionHeading
            title={t("rrPaymentMethod")}
            right={
              <View style={st.secure}>
                <Lock size={14} color={colors.neutral600} />
                <Text style={ps.meta}>{t("rrSecure")}</Text>
              </View>
            }
          />
          <NetworkTiles value={payment.provider} readOnly />
          <Text style={st.fieldLabel}>{t("rrMomoNumber")}</Text>
          <View style={st.numberBox} accessible accessibilityLabel={`${t("rrMomoNumber")} ${payment.payer_phone_e164}`}>
            <Smartphone size={18} color={colors.navy800} />
            <Text style={st.number}>{payment.payer_phone_e164}</Text>
          </View>
        </Card>
      ) : null}

      <View style={[st.statusCard, step.failed ? st.statusFailed : st.statusWaiting]}>
        {step.failed ? (
          <TintedIcon icon={ShieldAlert} tint="red" size={64} />
        ) : (
          <View style={st.spinner}>
            <ActivityIndicator size="large" color={colors.blue600} />
          </View>
        )}
        <View style={st.flex}>
          <StatusChip label={payment ? pInfo.label : t("payRecovering")} tone={payment ? pInfo.tone : "warning"} />
          {step.failed ? (
            <>
              <Text style={st.statusTitle}>{t("payNotCompleted")}</Text>
              <Text style={ps.body}>
                {t("payFailedBody", { status: payment?.status ? td(`status_${payment.status}`, payment.status).toLowerCase() : t("payFailedDefault") })}
              </Text>
            </>
          ) : payment ? (
            <>
              <Text style={st.statusTitle}>{t("ppAwaiting")}</Text>
              <Text style={ps.body}>{t("ppAwaitingBody", { provider: provider ?? "" })}</Text>
              <Text style={st.italic}>{t("ppFewSeconds")}</Text>
              <Text style={ps.meta}>{t("payApprove", { provider: provider ?? "", phone: payment.payer_phone_e164 })}</Text>
            </>
          ) : (
            <Text style={st.statusTitle}>{t("payRecoveringBody")}</Text>
          )}
        </View>
      </View>

      {error ? isProviderNotConfigured(error) ? <ProviderNotConfigured error={error} /> : <ErrorCard error={error} fallback={t("payStatusFailed")} /> : null}
      {waitingLong ? (
        <Card>
          <Text style={ps.body}>{t("payWaitingLong")}</Text>
        </Card>
      ) : null}
      {!step.failed ? <Banner icon={ShieldAlert} tint="gold" body={t("ppActivationNote", { provider: provider ?? t("coNetwork") })} /> : null}
      <Banner icon={Headset} tint="blue" title={t("ppNeedHelp")} body={t("ppNeedHelpBody")} onPress={() => router.push("/support/new")} />
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1, gap: 4 },
  secure: { flexDirection: "row", alignItems: "center", gap: 4 },
  fieldLabel: { ...type.label, color: colors.navy950 },
  numberBox: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 52, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.control, paddingHorizontal: space.x3, backgroundColor: colors.neutral50 },
  number: { ...type.body, color: colors.navy950, fontVariant: ["tabular-nums"] },
  statusCard: { flexDirection: "row", alignItems: "center", gap: space.x4, borderRadius: radius.feature, padding: space.x4 },
  statusWaiting: { backgroundColor: colors.blue50 },
  statusFailed: { backgroundColor: colors.dangerSoft },
  spinner: { width: 72, height: 72, alignItems: "center", justifyContent: "center" },
  statusTitle: { ...type.cardTitle, color: colors.navy950 },
  italic: { ...type.meta, fontStyle: "italic", color: colors.neutral500 },
});
