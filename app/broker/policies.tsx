import React, { useEffect, useMemo } from "react";
import { Text } from "react-native";
import { FileText } from "lucide-react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { byDate, byNumber, byText, FilterToolbar, filtersFromParams, optionsFrom, periodMatcher, periodSection, runList, sortSection, totals, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { loadBrokerLedger } from "@/components/partner/brokerLedger";
import { saleCommissionLine } from "@/components/partner/SaleCommission";
import { BrokerWorkspaceApi, money, shortDate, type PartnerPolicy } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const matchers: Matchers<PartnerPolicy> = {
  carrier_id: (p, v) => p.carrier_id === v,
  line_code: (p, v) => p.line_code === v,
  status: (p, v) => p.status === v,
  issued: periodMatcher((p) => p.issued_at),
};
const sorters: Sorters<PartnerPolicy> = {
  recent: byDate((p) => p.issued_at),
  expiry: byDate((p) => p.coverage_ends_at, "asc"),
  premium: byNumber((p) => p.premium_minor),
  name: byText((p) => p.customer_name),
};
const haystack = (p: PartnerPolicy) => [p.policy_number, p.customer_name, p.carrier_name, p.line_code];

export default function BrokerPolicies() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerWorkspaceApi.policies(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "carrier_id", title: t("fltInsurer"), options: optionsFrom(rows, (p) => ({ value: p.carrier_id, label: p.carrier_name })) },
      { key: "line_code", title: t("fltProductFamily"), options: optionsFrom(rows, (p) => (p.line_code ? { value: p.line_code, label: td(`line_${p.line_code}`, p.line_code) } : null)) },
      { key: "status", title: t("fltPolicyStatus"), options: optionsFrom(rows, (p) => ({ value: p.status, label: td(`policyStatus_${p.status}`, p.status) })) },
      periodSection(t, "issued", t("pdIssued")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "expiry", label: t("fltSortExpiry") },
        { value: "premium", label: t("fltSortAmountHigh") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  const f = useListFilters("broker.policies", sections, filtersFromParams(params));
  const { setText } = f;
  useEffect(() => {
    if (typeof params.q === "string" && params.q) setText(params.q);
  }, [params.q, setText]);
  const run = (v: FilterValues) => runList(rows, { values: v, text: f.query, matchers, haystack, sorters });
  const shown = run(f.values);
  const premium = totals(shown, (p) => p.premium_minor);
  // Commission per sale (server accruals by policy id); a failed ledger only hides the line.
  const ledger = useLoad(() => loadBrokerLedger().catch(() => null), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("policies")} subtitle={t("brPoliciesSubtitle")} />
      <FilterToolbar filters={f} sections={sections} count={(v) => run(v).length} resultCount={q.data ? shown.length : undefined} />
      {q.data && shown.length ? <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("brPremiumShown", { amount: money(premium.total) })}</Text> : null}
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("policiesLoading")}
        emptyTitle={t("policiesEmpty")}
        emptyMessage={t("agNoPoliciesBody")}
      >
        {() =>
          shown.length === 0 ? (
            <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={f.clear} />
          ) : (
            <OperationsList
              icon={FileText}
              onPress={(id) => router.push({ pathname: "/broker/policies/[id]", params: { id: id } })}
              rows={shown.map((p) => ({
                id: p.id,
                title: `${p.policy_number ?? t("bkPendingNumber")} · ${p.customer_name}`,
                subtitle: [
                  `${p.carrier_name} · ${money(p.premium_minor)} · ${t("bkEnds", { date: shortDate(p.coverage_ends_at) })}`,
                  saleCommissionLine(ledger.data, { policyId: p.id, premiumMinor: p.premium_minor }, t),
                ]
                  .filter(Boolean)
                  .join(" · "),
                status: td(`policyStatus_${p.status}`, p.status),
              }))}
            />
          )
        }
      </StatePanel>
    </PortalScreen>
  );
}
