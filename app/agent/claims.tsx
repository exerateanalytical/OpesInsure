import React from "react";
import { router } from "expo-router";
import { ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function AgentClaims() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.claims(), []);
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title={t("claims")} subtitle={t("ptClaimsSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("brNoClaims")}
        emptyMessage={t("brNoClaimsBody")}
      >
        {(x) => (
          <FilteredList
            list="agent.claims"
            rows={x}
            {...listSpec(x, t, {
              status: (c) => c.status,
              statusLabel: (v) => td(`claimStatus_${v}`, v),
              date: (c) => c.submitted_at,
              dateTitle: t("fltCreated"),
              amount: (c) => c.approved_amount_minor ?? c.estimated_loss_minor,
              name: (c) => c.customer_name,
            })}
            haystack={(c) => [c.claim_number, c.customer_name, c.policy_number, c.carrier_name, c.status]}
            placeholder={t("fltSearchQueue")}
            icon={ShieldAlert}
            render={(c) => ({
              title: `${c.claim_number} · ${c.customer_name}`,
              subtitle: [
                c.carrier_name,
                c.policy_number,
                c.approved_amount_minor !== null
                  ? `approved ${money(c.approved_amount_minor)}`
                  : c.estimated_loss_minor !== null
                    ? `estimated ${money(c.estimated_loss_minor)}`
                    : null,
                `filed ${shortDate(c.submitted_at)}`,
              ]
                .filter(Boolean)
                .join(" · "),
              status: c.status,
            })}
            onPress={(c) => router.push({ pathname: "/agent/claims/[id]", params: { id: c.id } })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
