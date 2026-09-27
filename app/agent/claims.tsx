import React from "react";
import { router } from "expo-router";
import { ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function AgentClaims() {
  const { t } = useTranslation();
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
          <OperationsList
            icon={ShieldAlert}
            rows={x.map((c) => ({
              id: c.id,
              title: `${c.claim_number} · ${c.customer_name}`,
              subtitle: [
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
            }))}
            onPress={(id) => router.push({ pathname: "/agent/claims/[id]", params: { id: id } })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
