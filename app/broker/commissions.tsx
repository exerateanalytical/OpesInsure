import React from "react";
import { StyleSheet, Text } from "react-native";
import { FileText } from "lucide-react-native";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CommissionLedger } from "@/components/partner/CommissionLedger";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { roleToPortal, useSession } from "@/store/session";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Broker commissions: shared filterable ledger (producer filter for broker admins only) + statements (BRK-007). */
export default function BrokerCommissions() {
  const { t } = useTranslation();
  const isBrokerAdmin = useSession((s) => roleToPortal(s.activeWorkspace?.role_code) === "broker_admin");
  const ledger = useLoad(() => BrokerWorkspaceApi.commissionLedger(), []);
  const q = useLoad(() => BrokerWorkspaceApi.commissions(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("agCommissions")} subtitle={t("brCommissionsSubtitle")} />
      <StatePanel
        {...ledger}
        onRetry={ledger.reload}
        loadingLabel={t("brLoadingCommissions")}
        emptyTitle={t("brNoCommission")}
        emptyMessage={t("brNoCommissionBody")}
      >
        {(rows) => (
          <CommissionLedger
            rows={rows}
            producers={isBrokerAdmin}
            onOpen={(r) => router.push({ pathname: "/broker/commissions/[id]", params: { id: r.id, kind: "accrual" } })}
          />
        )}
      </StatePanel>
      <SectionTitle title={t("brStatements")} />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("brLoadingCommissions")}>
        {(d) =>
          d.statements.length === 0 ? (
            <Card>
              <Text style={s.meta}>{t("brStatementsAppear")}</Text>
            </Card>
          ) : (
            <OperationsList
              icon={FileText}
              onPress={(id) => router.push({ pathname: "/broker/commissions/[id]", params: { id, kind: "statement" } })}
              rows={d.statements.map((st) => ({
                id: st.id,
                title: st.statement_number,
                subtitle: `${shortDate(st.period_start)} – ${shortDate(st.period_end)} · ${t("bkClosingBalance")} ${money(st.closing_balance_minor)}`,
                status: st.status,
              }))}
            />
          )
        }
      </StatePanel>
    </PortalScreen>
  );
}

const s = StyleSheet.create({
  meta: { ...type.meta, color: colors.neutral600 },
});
