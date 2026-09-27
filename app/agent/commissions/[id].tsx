import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Screen } from "@/components/ui";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { CommissionDetail } from "@/components/partner/CommissionDetail";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** COM-007: one of the agent's own accruals (server-scoped list, COM-008). */
export default function AgentCommissionDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => (await AgentWorkspaceApi.commissionLedger()).find((r) => r.id === id) ?? null, [id]);
  return (
    <Screen>
      <AppHeader title={t("pcDetailTitle")} subtitle={q.data?.policy_number ?? undefined} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(row) =>
          row ? (
            <CommissionDetail row={row} onOpenPolicy={(pid) => router.push({ pathname: "/agent/policies/[id]", params: { id: pid } })} />
          ) : (
            <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />
          )
        }
      </StatePanel>
    </Screen>
  );
}
