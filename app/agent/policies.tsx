import React from "react";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentShell } from "@/components/agent";
import { BookLoad, BookTitle } from "@/components/partner/AgentBookUi";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Policies tab (AGENT_UI_SPEC_V2 operational list): search + one filter icon, rows open the policy detail. */
export default function AgentPolicies() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.policies(), []);
  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <BookTitle title={t("policies")} subtitle={t("agPoliciesSubtitle")} />
      <BookLoad q={q} icon={FileText} emptyTitle={t("policiesEmpty")} emptyBody={t("agNoPoliciesBody")}>
        {(x) => (
          <FilteredList
            variant="agent"
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
              title: p.customer_name,
              subtitle: [
                p.policy_number ?? t("agkPendingNumber"),
                p.carrier_name,
                p.coverage_ends_at ? t("agkEnds", { date: shortDate(p.coverage_ends_at) }) : null,
              ]
                .filter(Boolean)
                .join(" · "),
              amount: money(p.premium_minor),
              statusCode: p.status,
              status: td(`policyStatus_${p.status}`, humanize(p.status)),
            })}
            onPress={(p) => router.push({ pathname: "/agent/policies/[id]", params: { id: p.id } })}
          />
        )}
      </BookLoad>
    </AgentShell>
  );
}
