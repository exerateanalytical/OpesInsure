import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { CircleDollarSign, FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { useColumns } from "@/components/responsive";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function BrokerCommissions() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerWorkspaceApi.commissions(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("agCommissions")} subtitle={t("brCommissionsSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("brLoadingCommissions")}
        isEmpty={(d) => d.accruals.length === 0 && d.statements.length === 0}
        emptyTitle={t("brNoCommission")}
        emptyMessage={t("brNoCommissionBody")}
      >
        {(d) => (
          <>
            <View style={grid.row}>
              {(
                [
                  [t("pending"), d.totals.pending_minor],
                  [t("offerAvailable"), d.totals.available_minor],
                  [t("claimStatus_PAID"), d.totals.paid_minor],
                ] as const
              ).map(([label, v]) => (
                <Card key={label} style={[s.metric, grid.item]}>
                  <Text style={s.meta}>{label}</Text>
                  <Text style={s.value}>{money(v)}</Text>
                </Card>
              ))}
            </View>
            <SectionTitle title={t("brStatements")} />
            {d.statements.length === 0 ? (
              <Card>
                <Text style={s.meta}>{t("brStatementsAppear")}</Text>
              </Card>
            ) : (
              <OperationsList
                icon={FileText}
                rows={d.statements.map((st) => ({
                  id: st.id,
                  title: st.statement_number,
                  subtitle: `${shortDate(st.period_start)} – ${shortDate(st.period_end)} · closing ${money(st.closing_balance_minor)}`,
                  status: st.status,
                }))}
              />
            )}
            <SectionTitle title={t("brAccruals")} />
            {d.accruals.length === 0 ? (
              <Card>
                <Text style={s.meta}>{t("brNoCommissionAccrued")}</Text>
              </Card>
            ) : (
              <OperationsList
                icon={CircleDollarSign}
                rows={d.accruals.map((a) => ({
                  id: a.id,
                  title: `${a.policy_number ?? "Policy"} · ${money(a.amount_minor)}`,
                  subtitle: [a.customer_name, a.available_at ? `available ${shortDate(a.available_at)}` : null]
                    .filter(Boolean)
                    .join(" · "),
                  status: a.status,
                }))}
              />
            )}
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

const s = StyleSheet.create({
  metric: { minHeight: 88 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});
