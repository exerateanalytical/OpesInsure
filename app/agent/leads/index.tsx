import React, { useState } from "react";
import { router } from "expo-router";
import { Plus, UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { ChoiceChips } from "@/components/portal/Workspace";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { isOpenLead } from "@/lib/crm";

type Filter = "OPEN" | LeadStatus;

export default function AgentLeads() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.leads(), []);
  const [filter, setFilter] = useState<Filter>("OPEN");
  const rows = (q.data ?? []).filter((l) =>
    filter === "OPEN" ? isOpenLead(l.status) : l.status === filter,
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
          <FilteredList
            list="agent.leads"
            icon={UserPlus}
            onPress={({ id }) => router.push(`/agent/leads/${id}`)}
            rows={x}
            {...listSpec(x, t, { status: (l) => l.status, statusLabel: (v) => td(`leadStatus_${v}`, v), dims: [{ key: "product_interest", title: t("fltProductLine"), get: (l) => ({ value: l.product_interest }) }], date: (l) => l.created_at, dateTitle: t("fltCreated"), name: (l) => l.full_name })}
            haystack={(l) => [l.full_name, l.phone_e164, l.product_interest, td(`leadStatus_${l.status}`, l.status)]}
            placeholder={t("fltSearchQueue")}
            render={(l) => ({
              title: l.full_name,
              subtitle: [l.phone_e164, l.product_interest, t("agLeadAdded", { date: shortDate(l.created_at) })].filter(Boolean).join(" · "),
              status: td(`leadStatus_${l.status}`, l.status),
            })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
