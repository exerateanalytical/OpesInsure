import React, { useCallback, useEffect, useState } from "react";
import { ActivityIndicator, AppState, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as Crypto from "expo-crypto";
import { CalendarDays, CheckCircle2, Coins, Lock, ShieldAlert } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, DetailRow, SectionHeading } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { NetworkTiles, type Network } from "@/components/policies/RenewalUi";
import { PaymentsApi } from "@/api/client";
import { InstalmentsApi, type InstalmentPayment } from "@/api/customerFlows";
import { isMomoPhone, normalizeMomoPhone } from "@/lib/momoPhone";
import { isProviderNotConfigured } from "@/lib/purchase";
import { instalmentTone } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const POLL_MS = 5000;
const DONE = new Set(["SUCCEEDED"]);
const FAILED = new Set(["FAILED", "EXPIRED", "CANCELLED"]);

/**
 * Pay one premium instalment (POST mobile/policies/{id}/instalments/{i}/pay): the normal
 * Mobile Money path, keyed to the instalment. One Idempotency-Key per attempt (kept across
 * retries of that attempt), and the server refuses a second charge while one is in flight
 * (409 PAYMENT_IN_PROGRESS) or once paid (409 PAYMENT_ALREADY_MADE). The status shown is
 * the server's (GET mobile/payments/{id}), polled until it settles.
 */
export default function InstalmentPay() {
  const { id, instalmentId } = useLocalSearchParams<{ id: string; instalmentId: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const phone = useSession((s) => s.bootstrap?.user?.phone_e164 ?? "");
  const q = useLoad(() => InstalmentsApi.schedule(id), [id]);
  const [network, setNetwork] = useState<Network>("mtn_momo");
  const [payer, setPayer] = useState(phone);
  const [key, setKey] = useState(() => Crypto.randomUUID());
  const [payment, setPayment] = useState<InstalmentPayment | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const status = String(payment?.status ?? "").toUpperCase();
  const done = DONE.has(status);
  const failed = FAILED.has(status);

  const check = useCallback(async () => {
    if (!payment) return;
    try {
      const p = await PaymentsApi.show(payment.id);
      setPayment((cur) => (cur ? { ...cur, status: p.status, provider_reference: p.provider_reference ?? cur.provider_reference } : cur));
    } catch {
      // Keep the last known status; the next poll retries.
    }
  }, [payment]);
  useEffect(() => {
    if (!payment || done || failed) return;
    const timer = setInterval(() => void check(), POLL_MS);
    const sub = AppState.addEventListener("change", (s) => s === "active" && void check());
    return () => {
      clearInterval(timer);
      sub.remove();
    };
  }, [payment, done, failed, check]);

  const pay = async () => {
    setBusy(true);
    setError(null);
    try {
      setPayment(await InstalmentsApi.pay(id, instalmentId, { provider: network, payer_phone_e164: normalizeMomoPhone(payer) }, key));
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  const retry = () => {
    setPayment(null);
    setKey(Crypto.randomUUID());
    setError(null);
  };

  return (
    <Screen
      footer={
        <CtaBar>
          {done ? (
            <Button label={t("instBackToPolicy")} onPress={() => router.back()} />
          ) : payment && !failed ? (
            <Button label={t("ppCompleted")} onPress={() => void check()} />
          ) : failed ? (
            <Button label={t("payTryAgain")} onPress={retry} />
          ) : (
            <Button label={t("instPayNow")} icon={Lock} loading={busy} disabled={busy || !isMomoPhone(payer)} onPress={() => void pay()} />
          )}
        </CtaBar>
      }
    >
      <BrandHeader title={t("instPayTitle")} subtitle={t("instPaySubtitle")} back right="help" />
      <StatePanel {...q} onRetry={q.reload} isEmpty={(s) => !s.data.some((i) => i.id === instalmentId)} emptyTitle={t("instPayNotFound")} emptyMessage={t("instPayNotFoundBody")} loadingLabel={t("instLoading")}>
        {(s) => {
          const i = s.data.find((x) => x.id === instalmentId)!;
          return (
            <>
              <Card style={s2.card}>
                <SectionHeading title={t("instNumber", { number: i.number })} right={<StatusChip label={td(`instStatus_${i.status}`, i.status)} tone={instalmentTone(i)} />} />
                {s.meta.policy_number ? <Text style={s2.meta}>{t("claimPolicyNo", { number: s.meta.policy_number })}</Text> : null}
                <DetailRow icon={CalendarDays} label={t("instDue")} value={f.date(i.due_date)} />
                <DetailRow icon={Coins} label={t("instAmountDue")} value={f.xaf(i.outstanding_minor)} strong />
              </Card>
              {payment ? (
                <View style={[s2.status, done ? s2.ok : failed ? s2.bad : s2.wait]}>
                  {done ? <CheckCircle2 size={40} color={colors.success} /> : failed ? <ShieldAlert size={40} color={colors.danger} /> : <ActivityIndicator size="large" color={colors.blue600} />}
                  <View style={s2.flex}>
                    <StatusChip label={td(`status_${status}`, status)} tone={done ? "success" : failed ? "danger" : "warning"} />
                    <Text style={s2.title}>{done ? t("instPaid") : failed ? t("payNotCompleted") : t("ppAwaiting")}</Text>
                    {!done && !failed ? <Text style={s2.body}>{t("payApprove", { provider: network === "mtn_momo" ? t("rrMtn") : t("rrOrange"), phone: payment.payer_phone_e164 })}</Text> : null}
                  </View>
                </View>
              ) : i.payable ? (
                <Card style={s2.card}>
                  <SectionHeading title={t("rrPaymentMethod")} />
                  <NetworkTiles value={network} onChange={setNetwork} disabled={busy} />
                  <TextField label={t("rrMomoNumber")} value={payer} onChangeText={setPayer} keyboardType="phone-pad" hint={t("instPhoneHint")} />
                </Card>
              ) : (
                <Banner icon={ShieldAlert} tint="gold" body={i.payment_in_progress ? t("instInProgress") : t("instNotPayable")} />
              )}
              {error ? isProviderNotConfigured(error) ? <ProviderNotConfigured error={error} /> : <ErrorCard error={error} fallback={t("payStatusFailed")} /> : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const s2 = StyleSheet.create({
  flex: { flex: 1, gap: 4 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  meta: { ...type.meta, color: colors.neutral600 },
  status: { flexDirection: "row", alignItems: "center", gap: space.x4, borderRadius: radius.feature, padding: space.x4 },
  wait: { backgroundColor: colors.blue50 },
  ok: { backgroundColor: colors.successSoft },
  bad: { backgroundColor: colors.dangerSoft },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});
