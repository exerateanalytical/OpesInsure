import React, { useCallback, useEffect, useRef, useState } from "react";
import { AppState, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Smartphone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, Stepper, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { useInsurance } from "@/store/insurance";
import { humanize, isNotFound, isProviderNotConfigured, paymentStatusInfo, purchaseStep } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const POLL_MS = 5000;
/** After this long without a decision, suggest leaving and checking later. */
const PATIENCE_MS = 3 * 60 * 1000;

export default function Payment() {
  const { proposalId } = useLocalSearchParams<{ proposalId?: string }>();
  const payment = useInsurance((s) => s.payment);
  const purchase = useInsurance((s) => s.purchase);
  const recover = useInsurance((s) => s.recoverPayment);
  const refresh = useInsurance((s) => s.refreshPayment);
  const refreshPurchase = useInsurance((s) => s.refreshPurchase);
  const f = useFormatters();
  const { t, td } = useTranslation();
  const STEPS = [t("payStepInitiated"), t("payStepAwaiting"), t("payStepConfirmed")];
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

  return (
    <Screen>
      <AppHeader title={t("payTitle")} subtitle={t("paySubtitle")} />
      <Stepper steps={STEPS} current={step.step} failed={step.failed} done={step.done} />
      <Card feature style={st.center}>
        <View style={st.icon}>
          <Smartphone size={36} color={colors.navy800} />
        </View>
        <StatusChip label={payment ? pInfo.label : t("payRecovering")} tone={payment ? pInfo.tone : "warning"} />
        {step.failed ? (
          <>
            <Text style={ps.title}>{t("payNotCompleted")}</Text>
            <Text style={[ps.body, st.text]}>
              {t("payFailedBody", { status: payment?.status ? td(`status_${payment.status}`, payment.status).toLowerCase() : t("payFailedDefault") })}
            </Text>
          </>
        ) : payment ? (
          <>
            <Text style={ps.title}>{t("payCheckPhone")}</Text>
            <Text style={[ps.body, st.text]}>{t("payApprove", { provider: humanize(payment.provider), phone: payment.payer_phone_e164 })}</Text>
            <Text style={ps.title}>{f.xaf(payment.amount_minor)}</Text>
          </>
        ) : (
          <Text style={ps.title}>{t("payRecoveringBody")}</Text>
        )}
      </Card>
      {error ? isProviderNotConfigured(error) ? <ProviderNotConfigured error={error} /> : <ErrorCard error={error} fallback={t("payStatusFailed")} /> : null}
      {waitingLong ? (
        <Card>
          <Text style={ps.body}>{t("payWaitingLong")}</Text>
        </Card>
      ) : null}
      {step.failed ? (
        <Button label={t("payTryAgain")} onPress={() => router.replace({ pathname: "/checkout", params: { proposalId: payment?.proposal_id ?? proposalId ?? "" } })} />
      ) : (
        <Button label={t("payRefresh")} loading={checking} variant="secondary" onPress={() => void check()} />
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
    </Screen>
  );
}

const st = StyleSheet.create({
  center: { alignItems: "center" },
  text: { textAlign: "center" },
  icon: { width: 72, height: 72, borderRadius: 36, alignItems: "center", justifyContent: "center" },
});
