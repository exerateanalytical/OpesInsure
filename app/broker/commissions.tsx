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

export default function BrokerCommissions() {
  const q = useLoad(() => BrokerWorkspaceApi.commissions(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title="Commissions" subtitle="Accruals and settlement statements" />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading commissions…"
        isEmpty={(d) => d.accruals.length === 0 && d.statements.length === 0}
        emptyTitle="No commission yet"
        emptyMessage="Commission accrues when policies you placed are paid and issued."
      >
        {(d) => (
          <>
            <View style={grid.row}>
              {(
                [
                  ["Pending", d.totals.pending_minor],
                  ["Available", d.totals.available_minor],
                  ["Paid", d.totals.paid_minor],
                ] as const
              ).map(([label, v]) => (
                <Card key={label} style={[s.metric, grid.item]}>
                  <Text style={s.meta}>{label}</Text>
                  <Text style={s.value}>{money(v)}</Text>
                </Card>
              ))}
            </View>
            <SectionTitle title="Statements" />
            {d.statements.length === 0 ? (
              <Card>
                <Text style={s.meta}>Statements appear here once finance approves them.</Text>
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
            <SectionTitle title="Accruals" />
            {d.accruals.length === 0 ? (
              <Card>
                <Text style={s.meta}>No commission accrued yet.</Text>
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
