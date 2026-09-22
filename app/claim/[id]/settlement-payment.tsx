import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Card, Money, Screen, StatusChip } from "@/components/ui";
import { ClaimSettlement, ClaimsCompletionApi } from "@/api/client";
export default function SettlementPayment() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimSettlement>();
  useEffect(() => {
    ClaimsCompletionApi.settlement(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader
        title="Settlement payment"
        subtitle="Payment status is verified by the server"
        back
      />
      <Card feature>
        <StatusChip
          label={x?.payment_status ?? "NOT AVAILABLE"}
          tone={x?.payment_status === "PAID" ? "success" : "warning"}
        />
        {x ? <Money amount={x.net_minor / 100} size="large" /> : null}
        <Text>
          Payment reference:{" "}
          {x?.payment_reference ?? "Assigned after payment processing"}
        </Text>
        <Text>
          OpesInsure never asks you to pay a fee or share a Mobile Money PIN to
          receive a claim settlement.
        </Text>
      </Card>
    </Screen>
  );
}
