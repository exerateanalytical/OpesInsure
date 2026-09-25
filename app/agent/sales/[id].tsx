import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
} from "@/components/ui";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function AgentSaleDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentApi.sale(id), [id]);
  const x = q.data;
  const setX = q.setData;
  return (
    <Screen>
      <AppHeader title={t("agAssistedSaleTitle")} subtitle={x?.customer_name} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card feature>
            <StatusChip label={x?.status ?? "LOADING"} tone="info" />
            <Text>{x?.product}</Text>
            {x ? <Money amount={x.premium_minor / 100} size="large" /> : null}
            <Text>Client phone: {x?.payment_phone_e164}</Text>
            <Text>Payment: {x?.payment_status.replaceAll("_", " ")}</Text>
            <Text>
              Estimated commission after verified payment:{" "}
              {x
                ? new Intl.NumberFormat("fr-CM").format(x.commission_minor / 100)
                : 0}{" "}
              FCFA
            </Text>
          </Card>
          <Button
            label={t("agSendPaymentRequest")}
            disabled={!x || x.payment_status === "PENDING_CLIENT"}
            onPress={async () => setX(await AgentApi.requestPayment(id))}
          />
          <Text>
            {t("agPaymentConfirmedByBackend")}
          </Text>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
