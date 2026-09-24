import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ClipboardCheck } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
export default function Referrals() {
  const q = useLoad(() => CarrierApi.referrals(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title="Underwriting referrals"
        subtitle="Decisions are recorded against delegated authority"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
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
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
