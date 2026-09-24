import React from "react";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";

export default function AgentPolicies() {
  const q = useLoad(() => AgentWorkspaceApi.policies(), []);
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title="Policies" subtitle="Cover held by clients attributed to you" />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading policies…"
        emptyTitle="No policies yet"
        emptyMessage="Policies issued to your clients will appear here."
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.policy_number ?? "Pending number"} · ${p.customer_name}`,
              subtitle: `${p.carrier_name} · ${money(p.premium_minor)} · ends ${shortDate(p.coverage_ends_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
