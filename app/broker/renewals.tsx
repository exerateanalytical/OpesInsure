import React, { useMemo } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { byDate, byText, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerApi, type AgentRenewal } from "@/api/client";
import { humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

const matchers: Matchers<AgentRenewal> = {
  status: (r, v) => r.status === v,
  expires: periodMatcher((r) => r.expires_at),
};
const sorters: Sorters<AgentRenewal> = {
  soonest: byDate((r) => r.expires_at, "asc"),
  latest: byDate((r) => r.expires_at),
  name: byText((r) => r.customer_name),
};
const haystack = (r: AgentRenewal) => [r.customer_name, r.policy_number, r.status];

export default function BrokerRenewals() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerApi.renewals(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("pcStatus"), options: optionsFrom(rows, (r) => ({ value: r.status, label: td(`renewalStatus_${r.status}`, humanize(r.status)) })) },
      periodSection(t, "expires", t("pdEnds")),
      sortSection(t, [
        { value: "soonest", label: t("fltSortExpiry") },
        { value: "latest", label: t("fltSortRecent") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  return (
    <Screen>
      <AppHeader title={t("brRenewals")} subtitle={t("agPoliciesDueSoon")} back />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("brNoRenewals")} emptyMessage={t("brNoRenewalsBody")}>
        {() => (
          <FilteredList
            list="broker.renewals"
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            initial={filtersFromParams(params)}
            icon={RefreshCw}
            onPress={(r) => router.push({ pathname: "/broker/renewals/[id]", params: { id: r.id } })}
            render={(r) => ({
              title: r.customer_name,
              subtitle: `${r.policy_number} · ${shortDate(r.expires_at)} · ${r.days_remaining < 0 ? t("pdExpiredAgo", { days: -r.days_remaining }) : t("pdDaysLeft", { days: r.days_remaining })}`,
              status: td(`renewalStatus_${r.status}`, humanize(r.status)),
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
