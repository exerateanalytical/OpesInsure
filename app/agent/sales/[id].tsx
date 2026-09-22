import React, { useEffect, useState } from "react";
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
import { AgentApi, AgentSale } from "@/api/client";
export default function AgentSaleDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<AgentSale>();
  useEffect(() => {
    AgentApi.sale(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Assisted sale" subtitle={x?.customer_name} back />
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
        label="Send payment request to client"
        disabled={!x || x.payment_status === "PENDING_CLIENT"}
        onPress={async () => setX(await AgentApi.requestPayment(id))}
      />
      <Text>
        Payment success and commission availability are confirmed only by the
        backend after the gateway webhook is verified.
      </Text>
    </Screen>
  );
}
