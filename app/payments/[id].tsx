import React, { useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { RefreshCcw } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { PaymentsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, isProviderNotConfigured, paymentStatusInfo } from "@/lib/purchase";

export default function PaymentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { data: p, setData, loading, error, reload } = useLoad(() => PaymentsApi.show(id), [id]);
  const [retrying, setRetrying] = useState(false);
  const [retryError, setRetryError] = useState<unknown>(null);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    setRetryError(null);
    try {
      setData(await PaymentsApi.retry(id));
    } catch (e) {
      setRetryError(e);
    } finally {
      setRetrying(false);
    }
  };

  const info = paymentStatusInfo(p?.status);
  return (
    <Screen>
      <AppHeader title="Payment details" back />
      {loading && !p ? <LoadingState label="Loading payment…" /> : null}
      {error && !p ? <ErrorCard error={error} fallback="This payment could not be loaded." onRetry={() => void reload()} /> : null}
      {p ? (
        <>
          <Card>
            <StatusChip label={info.label} tone={info.tone} />
            <Text style={ps.title}>{f.xaf(p.amount_minor)}</Text>
            <InfoRow label="Network" value={humanize(p.provider)} />
            <InfoRow label="Paying phone" value={p.payer_phone_e164} />
            {p.provider_reference ? <InfoRow label="Operator reference" value={p.provider_reference} /> : null}
            {p.created_at ? <InfoRow label="Requested" value={f.dateTime(p.created_at)} /> : null}
            {p.status === "SUCCEEDED" && p.updated_at ? <InfoRow label="Confirmed" value={f.dateTime(p.updated_at)} /> : null}
          </Card>
          {retryError ? isProviderNotConfigured(retryError) ? <ProviderNotConfigured error={retryError} /> : <ErrorCard error={retryError} fallback="The retry could not be started." /> : null}
          {p.status === "FAILED" ? <Button label="Retry safely" loading={retrying} onPress={() => void retry()} /> : null}
          {["PENDING_CUSTOMER", "PROCESSING", "CREATED"].includes(p.status) ? (
            <Button label="Refresh status" icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void reload()} />
          ) : null}
          {p.status === "SUCCEEDED" ? (
            <>
              <Button label="View receipt" variant="secondary" onPress={() => router.push({ pathname: "/payments/[id]/receipt", params: { id } })} />
              <Button label="Request refund review" variant="tertiary" onPress={() => router.push({ pathname: "/payments/[id]/refund", params: { id } })} />
            </>
          ) : null}
          {p.proposal_id ? <Button label="Open application" variant="tertiary" onPress={() => router.push({ pathname: "/proposals/[id]", params: { id: p.proposal_id } })} /> : null}
        </>
      ) : null}
    </Screen>
  );
}
