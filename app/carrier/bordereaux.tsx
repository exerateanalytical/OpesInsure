import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { FileSpreadsheet } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierFinanceApi, fcfa } from "@/api/extra";

const day = (v?: string | null) => (v ? new Date(v).toLocaleDateString() : "");

export default function CarrierBordereaux() {
  const q = useLoad(() => CarrierFinanceApi.bordereaux(), []);
  return (
    <Screen>
      <AppHeader
        title="Bordereaux"
        subtitle="Premium and commission bordereaux received"
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle="No bordereaux yet"
        emptyMessage="Bordereaux appear here once a broker submits them."
      >
        {(x) => (
          <OperationsList
            icon={FileSpreadsheet}
            rows={x.map((b) => ({
              id: b.id,
              title: b.bordereau_number,
              subtitle: `${day(b.period_start)} – ${day(b.period_end)} · ${b.item_count} items · ${fcfa(b.gross_premium_minor)}`,
              status: b.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
