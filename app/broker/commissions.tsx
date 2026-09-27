import React from "react";
import { StyleSheet, Text } from "react-native";
import { FileText } from "lucide-react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CommissionLedger } from "@/components/partner/CommissionLedger";
import { loadBrokerLedger } from "@/components/partner/brokerLedger";
import { filtersFromParams } from "@/components/filters";
import { BrokerWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { roleToPortal, useSession } from "@/store/session";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Broker earnings: shared filterable ledger (producer filter for broker admins only) + statements (BRK-007). */
export default function BrokerCommissions() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const isBrokerAdmin = useSession((s) => roleToPortal(s.activeWorkspace?.role_code) === "broker_admin");
  const ledger = useLoad(() => loadBrokerLedger(true), []);
  const q = useLoad(() => BrokerWorkspaceApi.commissions(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("portalTab_Earnings")} subtitle={t("brCommissionsSubtitle")} />
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
            listKey="broker.commissions"
            initial={filtersFromParams(params)}
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
                subtitle: `${shortDate(st.period_start)} – ${shortDate(st.period_end)} · ${t("bkEarned")} ${money(st.earned_minor)} · ${t("bkClosingBalance")} ${money(st.closing_balance_minor)}`,
                status: td(`commissionStatus_${st.status}`, humanize(st.status)),
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
