import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ArrowUpRight, CircleDollarSign } from "lucide-react-native";
import { AppHeader, Button, Card, Money, SectionTitle } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
import { fcfa } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";
export default function AgentWallet() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.commissions(), []);
  const x = q.data ?? [];
  const withdrawals = useLoad(() => AgentApi.withdrawals(), []);
  const available = x
    .filter((c) => c.status === "AVAILABLE")
    .reduce((n, c) => n + c.amount_minor, 0);
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
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            {x.map((c) => (
              <FlowRow
                key={c.id}
                icon={CircleDollarSign}
                title={`${new Intl.NumberFormat("fr-CM").format(c.amount_minor / 100)} FCFA`}
                subtitle={c.policy_id ?? c.reason ?? t("agCommissionAdjustment")}
                status={c.status}
              />
            ))}
          </Card>
          </>
        )}
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
              />
            ))}
          </Card>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
