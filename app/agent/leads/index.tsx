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
import { useTranslation } from "@/i18n";

type Filter = "OPEN" | LeadStatus;

export default function AgentLeads() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.leads(), []);
  const [filter, setFilter] = useState<Filter>("OPEN");
  const rows = (q.data ?? []).filter((l) =>
    filter === "OPEN" ? !["CONVERTED", "LOST"].includes(l.status) : l.status === filter,
  );
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title={t("portalTab_Leads")} subtitle={t("agLeadsSubtitle")} />
      <Button label={t("agAddLead")} icon={Plus} onPress={() => router.push("/agent/leads/new")} />
      <ChoiceChips<Filter>
        label={t("agFilterLeads")}
        value={filter}
        onChange={setFilter}
        options={[
          { value: "OPEN", label: t("supportStatus_OPEN") },
          { value: "CONVERTED", label: t("agLeadConverted") },
          { value: "LOST", label: t("agLeadLost") },
        ]}
      />
      <StatePanel
        {...q}
        data={q.data === undefined ? undefined : rows}
        onRetry={q.reload}
        loadingLabel={t("agLoadingLeads")}
        emptyTitle={t("agNoLeads")}
        emptyMessage={t("agNoLeadsBody")}
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
