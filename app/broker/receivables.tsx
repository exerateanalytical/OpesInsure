import React, { useMemo } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { useCursorList } from "@/hooks/useCursorList";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { BrokerPagedApi } from "@/api/workspace";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { CircleDollarSign, FileText, ReceiptText } from "lucide-react-native";
import { AppHeader, SectionTitle } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { FilteredList } from "@/components/partner/FilteredList";
import { byDate, byNumber, byText, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerApi } from "@/api/client";
import { BrokerFinanceApi, fcfa } from "@/api/extra";
import { humanize } from "@/api/partner";
import { formatDisplayDate, useTranslation } from "@/i18n";

const day = (iso?: string | null) =>
  iso ? formatDisplayDate(iso) : "";

type Receivable = Awaited<ReturnType<typeof BrokerApi.receivables>>[number] & { policy_number?: string | null; label?: string | null };
const matchers: Matchers<Receivable> = {
  status: (r, v) => r.status === v,
  due: periodMatcher((r) => r.due_at),
};
const sorters: Sorters<Receivable> = {
  due: byDate((r) => r.due_at, "asc"),
  amount: byNumber((r) => r.amount_minor),
  name: byText((r) => r.customer_name),
};
const haystack = (r: Receivable) => [r.customer_name, r.policy_number, r.label, r.status];

export default function Receivables() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  // Phase-1 fix S: cursor-paged (the list used to stop at the first 100 accruals).
  const q = useCursorList<Receivable>(() => BrokerPagedApi.receivables() as never);
  const statements = useLoad(() => BrokerFinanceApi.statements(), []);
  const accruals = useLoad(() => BrokerFinanceApi.accruals(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("pcStatus"), options: optionsFrom(rows, (r) => ({ value: r.status, label: td(`commissionStatus_${r.status}`, humanize(r.status)) })) },
      periodSection(t, "due", t("brDue")),
      sortSection(t, [
        { value: "due", label: t("brSortDueSoonest") },
        { value: "amount", label: t("fltSortAmountHigh") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader
        title={t("brReceivables")}
        subtitle={t("brLedgerAuthoritative")}
      />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("brNoReceivables")} emptyMessage={t("brNoReceivablesBody")}>
        {() => (
          <FilteredList
            list="broker.receivables"
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            initial={filtersFromParams(params)}
            icon={ReceiptText}
            amount={(r) => r.amount_minor}
            totalLabel={(amount, count) => t("brReceivablesTotal", { amount, count })}
            onPress={(r) => router.push({ pathname: "/broker/receivables/[id]", params: { id: r.id } })}
            render={(r) => ({
              title: r.customer_name,
              subtitle: [r.policy_number, fcfa(r.amount_minor), t("brDueOn", { date: day(r.due_at) })].filter(Boolean).join(" · "),
              status: td(`commissionStatus_${r.status}`, humanize(r.status)),
            })}
          />
        )}
      </StatePanel>
      <LoadMore {...q.more} />
      <SectionTitle title={t("brStatements")} />
      <StatePanel
        {...statements}
        onRetry={statements.reload}
        emptyTitle={t("brNoStatements")}
        emptyMessage={t("brNoStatementsBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            onPress={(id) => router.push({ pathname: "/broker/commissions/[id]", params: { id, kind: "statement" } })}
            rows={x.map((s) => ({
              id: s.id,
              title: s.statement_number,
              subtitle: `${day(s.period_start)} – ${day(s.period_end)} · ${t("bkClosingBalance")} ${fcfa(s.closing_balance_minor)}`,
              status: td(`commissionStatus_${s.status}`, humanize(s.status)),
            }))}
          />
        )}
      </StatePanel>
      <SectionTitle title={t("brCommissionAccruals")} />
      <StatePanel
        {...accruals}
        onRetry={accruals.reload}
        emptyTitle={t("brNoAccrued")}
        emptyMessage={t("brNoAccruedBody")}
      >
        {(x) => (
          <OperationsList
            icon={CircleDollarSign}
            onPress={(id) => router.push({ pathname: "/broker/commissions/[id]", params: { id, kind: "accrual" } })}
            rows={x.map((a) => ({
              id: a.id,
              title: fcfa(a.amount_minor),
              subtitle: a.vests_at
                ? t("brVestsOn", { date: day(a.vests_at) })
                : t("brAccruedOn", { date: day(a.created_at) }),
              status: td(`commissionStatus_${a.status}`, humanize(a.status)),
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
