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

export default function Refund() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
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
      <AppHeader title="Refund review" subtitle="Submitting does not cancel active cover automatically" back />
      {result ? (
        <Card feature>
          <StatusChip label={humanize(result.status)} tone="info" />
          <Text style={ps.title}>Refund request received</Text>
          {result.amount_minor ? <InfoRow label="Amount" value={f.xaf(result.amount_minor)} /> : null}
          <Text style={ps.body}>The insurer reviews refund requests. You will be notified of the decision.</Text>
          <Button label="Back to payment" variant="secondary" onPress={() => router.back()} />
        </Card>
      ) : (
        <Card>
          {payment.data ? <InfoRow label="Refund amount (full payment)" value={f.xaf(amount)} strong /> : payment.error ? <ErrorCard error={payment.error} fallback="Payment could not be loaded." onRetry={() => void payment.reload()} /> : null}
          <PickerField label="Reason" value={reasonCode || undefined} options={REFUND_REASONS.map((r) => ({ value: r.code, label: r.label }))} onChange={setReasonCode} />
          <TextField label="Tell us more" multiline value={reason} onChangeText={setReason} hint="At least 10 characters" editable={!busy} />
          {error ? <ErrorCard error={error} fallback="The refund request could not be submitted." onRetry={() => void submit()} /> : null}
          <Button label="Submit for review" loading={busy} disabled={!valid || busy} onPress={() => void submit()} />
        </Card>
      )}
    </Screen>
  );
}
