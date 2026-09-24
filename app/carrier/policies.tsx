import React from "react";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, money, shortDate } from "@/api/partner";

export default function CarrierPolicies() {
  const q = useLoad(() => CarrierWorkspaceApi.policies(), []);
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader title="Policies" subtitle="Policies written on your paper" />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading policies…"
        emptyTitle="No policies yet"
        emptyMessage="Policies you issue will appear here."
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.policy_number ?? "Pending number"} · ${p.customer_name}`,
              subtitle: `${money(p.premium_minor)} · ${shortDate(p.coverage_starts_at)} – ${shortDate(p.coverage_ends_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
