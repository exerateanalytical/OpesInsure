import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
} from "@/components/ui";
import { Payment, PaymentsApi } from "@/api/client";
export default function PaymentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [p, setP] = useState<Payment>();
  useEffect(() => {
    PaymentsApi.show(id).then(setP);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Payment details" back />
      <Card>
        <StatusChip
          label={p?.status ?? "LOADING"}
          tone={
            p?.status === "SUCCEEDED"
              ? "success"
              : p?.status === "FAILED"
                ? "danger"
                : "warning"
          }
        />
        {p ? <Money amount={p.amount_minor / 100} size="large" /> : null}
        <Text>{p?.provider.replaceAll("_", " ")}</Text>
        <Text>{p?.payer_phone_e164}</Text>
        {p?.status === "FAILED" ? (
          <Button
            label="Retry safely"
            onPress={async () => setP(await PaymentsApi.retry(id))}
          />
        ) : null}
        {p?.status === "SUCCEEDED" ? (
          <>
            <Button
              label="View receipt"
              variant="secondary"
              onPress={() => router.push(`/payments/${id}/receipt`)}
            />
            <Button
              label="Request refund review"
              variant="tertiary"
              onPress={() => router.push(`/payments/${id}/refund`)}
            />
          </>
        ) : null}
      </Card>
    </Screen>
  );
}
