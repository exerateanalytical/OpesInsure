import React from "react";
import { router } from "expo-router";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function AgentRenewals() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.renewals(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title={t("agRenewalPipeline")}
        subtitle={t("agRenewalSubtitle")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <FilteredList
            list="agent.renewals"
            icon={RefreshCw}
            rows={x}
            {...listSpec(x, t, { status: (r) => r.status, name: (r) => r.customer_name })}
            haystack={(r) => [r.customer_name, r.policy_number, r.status]}
            placeholder={t("fltSearchQueue")}
            render={(r) => ({ title: r.customer_name, subtitle: t("agDaysRemaining", { number: r.policy_number, days: r.days_remaining }), status: r.status })}
            onPress={(r) => router.push({ pathname: "/agent/policies/[id]", params: { id: r.id } })}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
