import React, { useMemo } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { BookOpenCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { loadBrokerLedger } from "@/components/partner/brokerLedger";
import { saleCommissionLine } from "@/components/partner/SaleCommission";
import { saleCommission } from "@/components/partner/commissionFilters";
import { byDate, byNumber, byText, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerApi, type BrokerProduction } from "@/api/client";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const matchers: Matchers<BrokerProduction> = {
  carrier: (p, v) => p.carrier_name === v,
  status: (p, v) => p.status === v,
  issued: periodMatcher((p) => p.issued_at),
};
const sorters: Sorters<BrokerProduction> = {
  recent: byDate((p) => p.issued_at),
  oldest: byDate((p) => p.issued_at, "asc"),
  premium: byNumber((p) => p.premium_minor),
  name: byText((p) => p.customer_name),
};
const haystack = (p: BrokerProduction) => [p.policy_number, p.customer_name, p.carrier_name, p.status];

/** Production register: every sale with its premium and the commission the server posted for it. */
export default function BrokerProductionScreen() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerApi.production(), []);
  const ledger = useLoad(() => loadBrokerLedger().catch(() => null), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "carrier", title: t("fltInsurer"), options: optionsFrom(rows, (p) => ({ value: p.carrier_name, label: p.carrier_name })) },
      { key: "status", title: t("fltPolicyStatus"), options: optionsFrom(rows, (p) => ({ value: p.status, label: td(`policyStatus_${p.status}`, humanize(p.status)) })) },
      periodSection(t, "issued", t("pdIssued")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "premium", label: t("fltSortAmountHigh") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  const earned = ledger.data ? rows.reduce((s, p) => s + (saleCommission(ledger.data!, { policyId: p.id })?.amount_minor ?? 0), 0) : null;
  return (
    <Screen>
      <AppHeader title={t("brProductionRegister")} subtitle={t("brProductionSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("brNoProduction")} emptyMessage={t("brNoProductionBody")}>
        {() => (
          <>
            {earned !== null ? <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("brCommissionAllSales", { amount: money(earned) })}</Text> : null}
            <FilteredList
              list="broker.production"
              rows={rows}
              sections={sections}
              matchers={matchers}
              haystack={haystack}
              sorters={sorters}
              initial={filtersFromParams(params)}
              icon={BookOpenCheck}
              amount={(p) => p.premium_minor}
              totalLabel={(amount, count) => t("brPremiumOf", { amount, count })}
              onPress={(p) => router.push({ pathname: "/broker/production/[id]", params: { id: p.id } })}
              render={(p) => ({
                title: `${p.policy_number} · ${p.customer_name}`,
                subtitle: [p.carrier_name, money(p.premium_minor), shortDate(p.issued_at), saleCommissionLine(ledger.data, { policyId: p.id, premiumMinor: p.premium_minor }, t)]
                  .filter(Boolean)
                  .join(" · "),
                status: td(`policyStatus_${p.status}`, humanize(p.status)),
              })}
            />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
