import React, { useState } from "react";
import { router } from "expo-router";
import { UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { FiltersSheet } from "@/components/filters";
import { StatePanel } from "@/components/StatePanel";
import { SearchBar } from "@/components/SearchBar";
import { AppHeader, Button } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { LeadDirectoryApi } from "@/api/crm";
import { shortDate } from "@/api/partner";
import { LEAD_STAGES } from "@/lib/crm";
import { useTranslation } from "@/i18n";

type Filter = "ALL" | (typeof LEAD_STAGES)[number];

/** REQ-CRM-001 lead directory (GET /crm/leads), scoped to the broker's own book. */
export default function BrokerLeads() {
  const { t, td } = useTranslation();
  const [filter, setFilter] = useState<Filter>("ALL");
  const [text, setText] = useState("");
  const [query, setQuery] = useState("");
  const [sheet, setSheet] = useState(false);
  // Stage was an inline chip row; it is the filter sheet's only section now (server-side filter).
  const sections = [{ key: "status", single: true, title: t("agFilterLeads"), options: [{ value: "ALL", label: t("searchAll") }, ...LEAD_STAGES.map((s) => ({ value: s as string, label: td(`leadStatus_${s}`, s) }))] }];
  const q = useLoad(() => LeadDirectoryApi.list({ status: filter === "ALL" ? undefined : filter, q: query || undefined }), [filter, query]);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("brLeads")} subtitle={t("brLeadsSubtitle")} back />
      <Button label={t("leadNewTitle")} icon={UserPlus} onPress={() => router.push("/broker/leads/new")} />
      <SearchBar value={text} onChangeText={setText} onSubmit={() => setQuery(text.trim())} placeholder={t("brSearchLeads")} label={t("brSearchLeads")} clearLabel={t("clearSearch")} onFilter={() => setSheet(true)} filterLabel={t("filtersTitle")} filterCount={filter === "ALL" ? 0 : 1} />
      <FiltersSheet visible={sheet} onClose={() => setSheet(false)} sections={sections} value={{ status: [filter] }} onApply={(v) => setFilter((v.status?.[0] ?? "ALL") as Filter)} count={() => q.data?.length ?? 0} />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("agLoadingLeads")} emptyTitle={t("agNoLeads")} emptyMessage={t("agNoLeadsBody")}>
        {(rows) => (
          <OperationsList
            icon={UserPlus}
            onPress={(id) => router.push(`/broker/leads/${id}`)}
            rows={rows.map((l) => ({
              id: l.id,
              title: l.full_name,
              subtitle: [l.phone_e164, l.product_interest, l.assigned_user_id ? null : t("brUnassigned"), shortDate(l.created_at)].filter(Boolean).join(" · "),
              status: td(`leadStatus_${l.status}`, l.status),
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
