import React, { useRef, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as Crypto from "expo-crypto";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { ErrorCard, InfoRow, PickerField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { PaymentsApi, RefundRequest } from "@/api/client";
import { handleStepUpRequired } from "@/security/step-up";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, REFUND_REASONS, refundPayload } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

export default function Refund() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t, td } = useTranslation();
  const payment = useLoad(() => PaymentsApi.show(id), [id]);
  const [reason, setReason] = useState("");
  const [reasonCode, setReasonCode] = useState<string>("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [result, setResult] = useState<RefundRequest | null>(null);
  // One key per refund request on this screen: re-submits after a timeout
  // or step-up return the same refund instead of opening a second one.
  const key = useRef(`refund:${id}:${Crypto.randomUUID()}`);

  const amount = payment.data?.amount_minor ?? 0;
  const valid = reason.trim().length >= 10 && !!reasonCode && amount > 0;
  const submit = async () => {
    if (busy || !valid) return;
    setBusy(true);
    setError(null);
    try {
      setResult(await PaymentsApi.refund(id, refundPayload({ reason, reasonCode, amountMinor: amount, idempotencyKey: key.current })));
    } catch (e) {
      if (!handleStepUpRequired(e, "PAYMENT_REFUND_REQUEST", `/payments/${id}/refund`)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("rfTitle")} subtitle={t("rfSubtitle")} back />
      {result ? (
        <Card feature>
          <StatusChip label={td(`status_${result.status}`, humanize(result.status))} tone="info" />
          <Text style={ps.title}>{t("rfReceived")}</Text>
          {result.amount_minor ? <InfoRow label={t("rfAmount")} value={f.xaf(result.amount_minor)} /> : null}
          <Text style={ps.body}>{t("rfReviewNote")}</Text>
          <Button label={t("rfBack")} variant="secondary" onPress={() => router.back()} />
        </Card>
      ) : (
        <Card>
          {payment.data ? <InfoRow label={t("rfFullAmount")} value={f.xaf(amount)} strong /> : payment.error ? <ErrorCard error={payment.error} fallback={t("rfPaymentFailed")} onRetry={() => void payment.reload()} /> : null}
          <PickerField label={t("rfReason")} value={reasonCode || undefined} options={REFUND_REASONS.map((r) => ({ value: r.code, label: td(`refundReason_${r.code}`, r.label) }))} onChange={setReasonCode} />
          <TextField label={t("rfTellMore")} multiline value={reason} onChangeText={setReason} hint={t("rfMin10")} editable={!busy} />
          {error ? <ErrorCard error={error} fallback={t("rfSubmitFailed")} onRetry={() => void submit()} /> : null}
          <Button label={t("rfSubmit")} loading={busy} disabled={!valid || busy} onPress={() => void submit()} />
        </Card>
      )}
    </Screen>
  );
}
