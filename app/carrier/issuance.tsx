import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { FileCheck2 } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
export default function Issuance() {
  const q = useLoad(() => CarrierApi.issuance(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title="Issuance queue"
        subtitle="Payment and underwriting must be verified before issue"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={FileCheck2}
            rows={x.map((i) => ({
              id: i.id,
              title: i.reference,
              subtitle: i.subject,
              status: i.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
