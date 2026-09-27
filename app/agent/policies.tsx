import React from "react";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function AgentPolicies() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.policies(), []);
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title={t("policies")} subtitle={t("agPoliciesSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("policiesLoading")}
        emptyTitle={t("policiesEmpty")}
        emptyMessage={t("agNoPoliciesBody")}
      >
        {(x) => (
          <FilteredList
            list="agent.policies"
            rows={x}
            {...listSpec(x, t, {
              status: (p) => p.status,
              statusLabel: (v) => td(`policyStatus_${v}`, v),
              dims: [
                { key: "carrier_id", title: t("fltInsurer"), get: (p) => ({ value: p.carrier_id, label: p.carrier_name }) },
                { key: "line_code", title: t("fltProductLine"), get: (p) => (p.line_code ? { value: p.line_code, label: td(`line_${p.line_code}`, p.line_code) } : null) },
              ],
              date: (p) => p.coverage_ends_at,
              dateTitle: t("fltPolicyEnds"),
              amount: (p) => p.premium_minor,
              name: (p) => p.customer_name,
            })}
            haystack={(p) => [p.policy_number, p.customer_name, p.carrier_name, p.line_code, p.status]}
            placeholder={t("fltSearchQueue")}
            icon={FileText}
            amount={(p) => p.premium_minor}
            render={(p) => ({
              title: `${p.policy_number ?? "Pending number"} · ${p.customer_name}`,
              subtitle: `${p.carrier_name} · ${money(p.premium_minor)} · ends ${shortDate(p.coverage_ends_at)}`,
              status: p.status,
            })}
            onPress={(p) => router.push({ pathname: "/agent/policies/[id]", params: { id: p.id } })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
