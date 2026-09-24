import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { ReceiptText } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
export default function Receivables() {
  const q = useLoad(() => BrokerApi.receivables(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader
        title="Receivables"
        subtitle="Backend ledger remains authoritative"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={ReceiptText}
            rows={x.map((r) => ({
              id: r.id,
              title: r.customer_name,
              subtitle: `${new Intl.NumberFormat("fr-CM").format(r.amount_minor / 100)} FCFA · due ${r.due_at}`,
              status: r.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
