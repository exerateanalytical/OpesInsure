import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ArrowUpRight } from "lucide-react-native";
import { AppHeader, Button, Card, Money, SectionTitle } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
import { AgentWorkspaceApi } from "@/api/partner";
import { CommissionLedger } from "@/components/partner/CommissionLedger";
import { commissionTotals } from "@/components/partner/commissionFilters";
import { fcfa } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";
export default function AgentWallet() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.commissionLedger(), []);
  const x = q.data ?? [];
  const withdrawals = useLoad(() => AgentApi.withdrawals(), []);
  const available = commissionTotals(x).available;
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader
        title={t("agCommissionWallet")}
        subtitle={t("agWalletSubtitle")}
      />
      <Card feature>
        <Money amount={available / 100} size="large" />
        <Button
          label={t("agWithdrawAvailable")}
          onPress={() => router.push("/agent/withdrawal")}
        />
      </Card>
      <SectionTitle title={t("pcLedger")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle={t("brNoCommission")}
        emptyMessage={t("brNoCommissionBody")}
      >
        {(rows) => <CommissionLedger rows={rows} onOpen={(r) => router.push({ pathname: "/agent/commissions/[id]", params: { id: r.id } })} />}
      </StatePanel>
      <SectionTitle title={t("agWithdrawalHistory")} />
      <StatePanel
        {...withdrawals}
        onRetry={withdrawals.reload}
        emptyTitle={t("agNoWithdrawals")}
        emptyMessage={t("agNoWithdrawalsBody")}
      >
        {(w) => (
          <Card>
            {w.map((r) => (
              <FlowRow
                key={r.id}
                icon={ArrowUpRight}
                title={fcfa(r.amount_minor)}
                subtitle={`${r.provider === "orange_money" ? "Orange Money" : r.provider === "mtn_momo" ? "MTN MoMo" : r.provider} · ${r.destination_phone} · ${formatDisplayDate(r.requested_at)}`}
                status={r.status}
                onPress={() => router.push({ pathname: "/agent/withdrawals/[id]", params: { id: r.id } })}
              />
            ))}
          </Card>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
