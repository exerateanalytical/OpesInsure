import React, { useState } from "react";
import { router } from "expo-router";
import { Plus, UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { ChoiceChips } from "@/components/portal/Workspace";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";

type Filter = "OPEN" | LeadStatus;

export default function AgentLeads() {
  const q = useLoad(() => AgentWorkspaceApi.leads(), []);
  const [filter, setFilter] = useState<Filter>("OPEN");
  const rows = (q.data ?? []).filter((l) =>
    filter === "OPEN" ? !["CONVERTED", "LOST"].includes(l.status) : l.status === filter,
  );
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title="Leads" subtitle="Prospects you are working before they become clients" />
      <Button label="Add a lead" icon={Plus} onPress={() => router.push("/agent/leads/new")} />
      <ChoiceChips<Filter>
        label="Filter leads"
        value={filter}
        onChange={setFilter}
        options={[
          { value: "OPEN", label: "Open" },
          { value: "CONVERTED", label: "Converted" },
          { value: "LOST", label: "Lost" },
        ]}
      />
      <StatePanel
        {...q}
        data={q.data === undefined ? undefined : rows}
        onRetry={q.reload}
        loadingLabel="Loading leads…"
        emptyTitle="No leads here"
        emptyMessage="Add a prospect to start tracking your follow-ups."
      >
        {(x) => (
          <OperationsList
            icon={UserPlus}
            onPress={(id) => router.push(`/agent/leads/${id}`)}
            rows={x.map((l) => ({
              id: l.id,
              title: l.full_name,
              subtitle: [l.phone_e164, l.product_interest, `added ${shortDate(l.created_at)}`].filter(Boolean).join(" · "),
              status: l.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
