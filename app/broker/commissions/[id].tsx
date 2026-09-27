import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Screen } from "@/components/ui";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { CommissionDetail } from "@/components/partner/CommissionDetail";
import { DetailScreen, DetailSection } from "@/components/detail";
import { BrokerWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

const scalar = (v: unknown) => (v === null || v === undefined || typeof v === "object" ? null : String(v));
const HIDDEN = /(^id$|tenant|partner|_id$|created_at|updated_at|version)/;

/** Commission statement (list row + GET /mobile/broker/statements/{id} audit fields). */
function StatementDetail({ id }: { id: string }) {
  const { t } = useTranslation();
  const q = useLoad(async () => {
    const [d, server] = await Promise.all([BrokerWorkspaceApi.commissions(), BrokerWorkspaceApi.statement(id).catch(() => ({}) as Record<string, unknown>)]);
    const row = d.statements.find((s) => s.id === id);
    return row ? { row, server } : null;
  }, [id]);
  return (
    <DetailScreen title={t("bkStatementSubtitle")} subtitle={(d) => d?.row.statement_number} query={q} isMissing={(d) => d === null}>
      {(d) =>
        d ? (
          <>
            <DetailSection
              title={t("brStatements")}
              rows={[
                [t("bkStatementNumber"), d.row.statement_number],
                [t("pcStatus"), humanize(d.row.status)],
                [t("bkPeriod"), `${shortDate(d.row.period_start)} – ${shortDate(d.row.period_end)}`],
                [t("bkEarned"), money(d.row.earned_minor)],
                [t("claimStatus_PAID"), money(d.row.paid_minor)],
                [t("bkClosingBalance"), money(d.row.closing_balance_minor)],
                [t("bkCurrency"), d.row.currency],
              ]}
            />
            <DetailSection
              title={t("bkAuditDetail")}
              rows={Object.entries(d.server)
                .filter(([k, v]) => !HIDDEN.test(k) && scalar(v) !== null && !(k in d.row))
                .map(([k, v]) => [humanize(k.toUpperCase()), /_minor$/.test(k) ? money(Number(v)) : /(_at|_on)$/.test(k) ? shortDate(String(v)) : scalar(v)])}
            />
          </>
        ) : null
      }
    </DetailScreen>
  );
}

/** BRK-007 commission accrual (shared CommissionDetail) or statement (?kind=statement). */
export default function BrokerCommissionDetail() {
  const { t } = useTranslation();
  const { id, kind } = useLocalSearchParams<{ id: string; kind?: string }>();
  const q = useLoad(async () => (kind === "statement" ? null : ((await BrokerWorkspaceApi.commissionLedger()).find((r) => r.id === id) ?? null)), [id, kind]);
  if (kind === "statement") return <StatementDetail id={id} />;
  return (
    <Screen>
      <AppHeader title={t("pcDetailTitle")} subtitle={q.data?.policy_number ?? undefined} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(row) =>
          row ? (
            <CommissionDetail row={row} onOpenPolicy={(pid) => router.push({ pathname: "/broker/policies/[id]", params: { id: pid } })} />
          ) : (
            <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />
          )
        }
      </StatePanel>
    </Screen>
  );
}
