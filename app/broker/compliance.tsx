import React, { useMemo } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { BadgeCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { byDate, byText, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerApi, type BrokerComplianceItem } from "@/api/client";
import { humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

const SEVERITY_RANK: Record<string, number> = { CRITICAL: 0, HIGH: 1, MEDIUM: 2, LOW: 3 };
const matchers: Matchers<BrokerComplianceItem> = {
  status: (c, v) => c.status === v,
  severity: (c, v) => c.severity === v,
  due: periodMatcher((c) => c.due_at),
};
const sorters: Sorters<BrokerComplianceItem> = {
  due: byDate((c) => c.due_at, "asc"),
  severity: (a, b) => (SEVERITY_RANK[a.severity] ?? 9) - (SEVERITY_RANK[b.severity] ?? 9),
  name: byText((c) => c.label),
};
const haystack = (c: BrokerComplianceItem) => [c.label, c.status, c.severity];

export default function BrokerCompliance() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerApi.compliance(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("pcStatus"), options: optionsFrom(rows, (c) => ({ value: c.status, label: td(`complianceStatus_${c.status}`, humanize(c.status)) })) },
      { key: "severity", title: t("brSeverity"), options: optionsFrom(rows, (c) => ({ value: c.severity, label: td(`severity_${c.severity}`, humanize(c.severity)) })) },
      periodSection(t, "due", t("brDue")),
      sortSection(t, [
        { value: "due", label: t("brSortDueSoonest") },
        { value: "severity", label: t("brSortSeverity") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  return (
    <Screen>
      <AppHeader title={t("brCompliance")} subtitle={t("brComplianceSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("brNoCompliance")} emptyMessage={t("brNoComplianceBody")}>
        {() => (
          <FilteredList
            list="broker.compliance"
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            initial={filtersFromParams(params)}
            icon={BadgeCheck}
            onPress={(c) => router.push({ pathname: "/broker/compliance/[id]", params: { id: c.id } })}
            render={(c) => ({
              title: c.label,
              subtitle: `${t("brDueOn", { date: shortDate(c.due_at) })} · ${td(`severity_${c.severity}`, humanize(c.severity))}`,
              status: td(`complianceStatus_${c.status}`, humanize(c.status)),
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
