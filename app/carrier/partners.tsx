import React from "react";
import { Handshake } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, humanize, money } from "@/api/partner";

export default function CarrierPartners() {
  const q = useLoad(() => CarrierWorkspaceApi.partners(), []);
  return (
    <Screen>
      <AppHeader title="Distribution partners" subtitle="Brokers and agents selling your products" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading partners…"
        emptyTitle="No distribution partners yet"
        emptyMessage="Brokers and agents appear here once they place policies or hold an agreement with you."
      >
        {(x) => (
          <OperationsList
            icon={Handshake}
            rows={x.map((p) => ({
              id: p.id,
              title: p.name,
              subtitle: [
                humanize(p.type),
                `${p.policies} policies · ${money(p.premium_minor)}`,
                p.agreement_number ? `agreement ${p.agreement_number}` : null,
              ]
                .filter(Boolean)
                .join(" · "),
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
