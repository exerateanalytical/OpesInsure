import React from "react";
import { router } from "expo-router";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { useLoad } from "@/hooks/useLoad";
import { RefreshCw } from "lucide-react-native";
import { AgentShell } from "@/components/agent";
import { BookLoad, bookStyles } from "@/components/partner/AgentBookUi";
import { Text } from "react-native";
import { AgentApi } from "@/api/client";
import { humanize } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Renewal pipeline (AGENT_UI_SPEC_V2 drill-down list): search + one filter icon, rows open the policy. */
export default function AgentRenewals() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentApi.renewals(), []);
  return (
    <AgentShell variant="drilldown" title={t("agRenewalPipeline")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <Text style={bookStyles.body}>{t("agRenewalSubtitle")}</Text>
      <BookLoad q={q} icon={RefreshCw} emptyTitle={t("agkNoRenewals")} emptyBody={t("agkNoRenewalsBody")}>
        {(x) => (
          <FilteredList
            variant="agent"
            list="agent.renewals"
            icon={RefreshCw}
            rows={x}
            {...listSpec(x, t, { status: (r) => r.status, name: (r) => r.customer_name })}
            haystack={(r) => [r.customer_name, r.policy_number, r.status]}
            placeholder={t("fltSearchQueue")}
            render={(r) => ({
              title: r.customer_name,
              subtitle: t("agDaysRemaining", { number: r.policy_number, days: r.days_remaining }),
              statusCode: r.status,
              status: td(`policyStatus_${r.status}`, humanize(r.status)),
            })}
            onPress={(r) => router.push({ pathname: "/agent/policies/[id]", params: { id: r.id } })}
          />
        )}
      </BookLoad>
    </AgentShell>
  );
}
