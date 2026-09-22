import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { CreditCard } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { Payment, PaymentsApi } from "@/api/client";
export default function Payments() {
  const [x, setX] = useState<Payment[]>([]);
  useEffect(() => {
    PaymentsApi.list().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Payments & receipts"
        subtitle="Every transaction, status and recovery action"
        back
      />
      <Card>
        {x.map((p) => (
          <FlowRow
            key={p.id}
            icon={CreditCard}
            title={`${new Intl.NumberFormat("fr-CM").format(p.amount_minor / 100)} FCFA`}
            subtitle={`${p.provider.replaceAll("_", " ")} · ${p.payer_phone_e164}`}
            status={p.status}
            onPress={() => router.push(`/payments/${p.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
