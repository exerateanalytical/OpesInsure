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
import { useTranslation } from "@/i18n";

export default function PaymentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t } = useTranslation();
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

  const info = paymentStatusInfo(p?.status, f.language);
  return (
    <Screen>
      <AppHeader title={t("pmTitle")} back />
      {loading && !p ? <LoadingState label={t("pmLoading")} /> : null}
      {error && !p ? <ErrorCard error={error} fallback={t("pmLoadFailed")} onRetry={() => void reload()} /> : null}
      {p ? (
        <>
          <Card>
            <StatusChip label={info.label} tone={info.tone} />
            <Text style={ps.title}>{f.xaf(p.amount_minor)}</Text>
            <InfoRow label={t("pmNetwork")} value={humanize(p.provider)} />
            <InfoRow label={t("pmPayingPhone")} value={p.payer_phone_e164} />
            {p.provider_reference ? <InfoRow label={t("pmOperatorRef")} value={p.provider_reference} /> : null}
            {p.created_at ? <InfoRow label={t("pmRequested")} value={f.dateTime(p.created_at)} /> : null}
            {p.status === "SUCCEEDED" && p.updated_at ? <InfoRow label={t("pmConfirmed")} value={f.dateTime(p.updated_at)} /> : null}
          </Card>
          {retryError ? isProviderNotConfigured(retryError) ? <ProviderNotConfigured error={retryError} /> : <ErrorCard error={retryError} fallback={t("pmRetryFailed")} /> : null}
          {p.status === "FAILED" ? <Button label={t("pmRetry")} loading={retrying} onPress={() => void retry()} /> : null}
          {["PENDING_CUSTOMER", "PROCESSING", "CREATED"].includes(p.status) ? (
            <Button label={t("pmRefresh")} icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void reload()} />
          ) : null}
          {p.status === "SUCCEEDED" ? (
            <>
              <Button label={t("pmViewReceipt")} variant="secondary" onPress={() => router.push({ pathname: "/payments/[id]/receipt", params: { id } })} />
              <Button label={t("pmRefund")} variant="tertiary" onPress={() => router.push({ pathname: "/payments/[id]/refund", params: { id } })} />
            </>
          ) : null}
          {p.proposal_id ? <Button label={t("coOpenApplication")} variant="tertiary" onPress={() => router.push({ pathname: "/proposals/[id]", params: { id: p.proposal_id } })} /> : null}
        </>
      ) : null}
    </Screen>
  );
}
