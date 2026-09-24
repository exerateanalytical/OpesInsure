import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { CircleDollarSign, FileText, ReceiptText } from "lucide-react-native";
import { AppHeader, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
import { BrokerFinanceApi, fcfa } from "@/api/extra";

const day = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleDateString() : "";

export default function Receivables() {
  const q = useLoad(() => BrokerApi.receivables(), []);
  const statements = useLoad(() => BrokerFinanceApi.statements(), []);
  const accruals = useLoad(() => BrokerFinanceApi.accruals(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader
        title="Receivables"
        subtitle="Backend ledger remains authoritative"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {(x) => (
          <OperationsList
            icon={ReceiptText}
            rows={x.map((r) => ({
              id: r.id,
              title: r.customer_name,
              subtitle: `${fcfa(r.amount_minor)} · due ${r.due_at}`,
              status: r.status,
            }))}
          />
        )}
      </StatePanel>
      <SectionTitle title="Statements" />
      <StatePanel
        {...statements}
        onRetry={statements.reload}
        emptyTitle="No statements yet"
        emptyMessage="Commission statements appear here once finance publishes them."
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            rows={x.map((s) => ({
              id: s.id,
              title: s.statement_number,
              subtitle: `${day(s.period_start)} – ${day(s.period_end)} · closing ${fcfa(s.closing_balance_minor)}`,
              status: s.status,
            }))}
          />
        )}
      </StatePanel>
      <SectionTitle title="Commission accruals" />
      <StatePanel
        {...accruals}
        onRetry={accruals.reload}
        emptyTitle="No commission accrued yet"
        emptyMessage="Commission accrues when a policy you placed is issued."
      >
        {(x) => (
          <OperationsList
            icon={CircleDollarSign}
            rows={x.map((a) => ({
              id: a.id,
              title: fcfa(a.amount_minor),
              subtitle: a.vests_at
                ? `Vests ${day(a.vests_at)}`
                : `Accrued ${day(a.created_at)}`,
              status: a.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
