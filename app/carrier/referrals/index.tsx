import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { ClipboardCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi, CarrierReferral } from "@/api/client";
export default function Referrals() {
  const [x, setX] = useState<CarrierReferral[]>([]);
  useEffect(() => {
    CarrierApi.referrals().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Underwriting referrals"
        subtitle="Decisions are recorded against delegated authority"
        back
      />
      <OperationsList
        icon={ClipboardCheck}
        rows={x.map((r) => ({
          id: r.id,
          title: r.customer_name,
          subtitle: `${r.product} · ${r.reason}`,
          status: r.status,
        }))}
        onPress={(id) => router.push(`/carrier/referrals/${id}`)}
      />
    </Screen>
  );
}
