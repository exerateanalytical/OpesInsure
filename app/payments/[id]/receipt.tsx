import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Card, Money, Screen, StatusChip } from "@/components/ui";
import { PaymentReceipt, PaymentsApi } from "@/api/client";
export default function Receipt() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [r, setR] = useState<PaymentReceipt>();
  useEffect(() => {
    PaymentsApi.receipt(id).then(setR);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Official receipt" back />
      <Card feature>
        <StatusChip label="PAID" tone="success" />
        <Text>{r?.receipt_number}</Text>
        {r ? <Money amount={r.amount_minor / 100} size="large" /> : null}
        <Text>Issued {r?.issued_at}</Text>
        <Text>
          This receipt is linked to the immutable payment reference above.
        </Text>
      </Card>
    </Screen>
  );
}
