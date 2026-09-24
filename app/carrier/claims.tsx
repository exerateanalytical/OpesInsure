import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { ShieldAlert } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
export default function CarrierClaims() {
  const q = useLoad(() => CarrierApi.claims(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title="Carrier claims queue"
        subtitle="Permission-scoped assessment work"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={ShieldAlert}
            rows={x.map((i) => ({
              id: i.id,
              title: i.reference,
              subtitle: `${i.subject} · ${i.priority}`,
              status: i.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
