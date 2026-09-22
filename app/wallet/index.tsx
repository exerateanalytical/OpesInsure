import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { ShieldCheck } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { WalletApi, WalletPolicy } from "@/api/client";
export default function Wallet() {
  const [x, setX] = useState<WalletPolicy[]>([]);
  useEffect(() => {
    WalletApi.list().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Policy wallet"
        subtitle="Cover, documents and physical sticker delivery"
        back
      />
      <Card>
        {x.map((p) => (
          <FlowRow
            key={p.id}
            icon={ShieldCheck}
            title={p.policy_number}
            subtitle={p.product_name ?? p.carrier_name}
            status={p.status}
            onPress={() => router.push(`/wallet/policy/${p.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
