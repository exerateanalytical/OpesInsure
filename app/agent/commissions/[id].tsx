import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { CircleDollarSign } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { CommissionDetail } from "@/components/partner/CommissionDetail";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** COM-007: one of the agent's own accruals (server-scoped list, COM-008). Spec v2 screen 07. */
export default function AgentCommissionDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => (await AgentWorkspaceApi.commissionLedger()).find((r) => r.id === id) ?? null, [id]);
  return (
    <AgentShell variant="drilldown" title={t("ernDetailTitle")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} height={56} />
      ) : q.error ? (
        <AgentEmptyState icon={CircleDollarSign} title={t("ernLoadError")} body={t("loadErrorBody")} actionLabel={t("ernRetry")} onAction={q.reload} />
      ) : q.data ? (
        <CommissionDetail
          variant="agent"
          row={q.data}
          onOpenPolicy={(pid) => router.push({ pathname: "/agent/policies/[id]", params: { id: pid } })}
          onWithdraw={() => router.push("/agent/withdrawal")}
        />
      ) : (
        <AgentEmptyState icon={CircleDollarSign} title={t("pdNotFound")} body={t("ernNotFound")} />
      )}
    </AgentShell>
  );
}
