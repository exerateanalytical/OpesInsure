import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { HandCoins } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { useColumns } from "@/components/responsive";
import { CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";

export default function CarrierPayments() {
  const q = useLoad(() => CarrierWorkspaceApi.payments(), []);
  const grid = useColumns();
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader title="Payments" subtitle="Premium payments and reconciliation" />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading payments…"
        isEmpty={(d) => d.items.length === 0}
        emptyTitle="No payments yet"
        emptyMessage="Premium payments for your policies will appear here."
      >
        {(d) => (
          <>
            <View style={grid.row}>
              {(
                [
                  ["Collected", money(d.summary.succeeded_minor)],
                  ["Reconciled", money(d.summary.reconciled_minor)],
                  ["Awaiting reconciliation", String(d.summary.unreconciled_count)],
                  ["Exceptions", String(d.summary.exception_count)],
                ] as const
              ).map(([label, value]) => (
                <Card key={label} style={[s.metric, grid.item]}>
                  <Text style={s.meta}>{label}</Text>
                  <Text style={s.value}>{value}</Text>
                </Card>
              ))}
            </View>
            <SectionTitle title="Payments" />
            <OperationsList
              icon={HandCoins}
              rows={d.items.map((p) => ({
                id: p.id,
                title: `${p.customer_name} · ${money(p.amount_minor)}`,
                subtitle: [p.proposal_number, p.provider, shortDate(p.created_at), humanize(p.reconciliation_status), p.exception_code]
                  .filter(Boolean)
                  .join(" · "),
                status: p.status,
              }))}
            />
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
