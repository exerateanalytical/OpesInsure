import React, { useMemo } from "react";
import { FileSignature } from "lucide-react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { byDate, byNumber, byText, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerWorkspaceApi, money, shortDate, type PartnerQuote } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { quoteOutcome } from "@/lib/quoteWorkflow";

const outcome = (r: PartnerQuote) => quoteOutcome(r) ?? r.status;
const matchers: Matchers<PartnerQuote> = {
  status: (r, v) => outcome(r) === v,
  line: (r, v) => r.line_code === v,
  created: periodMatcher((r) => r.created_at),
};
const sorters: Sorters<PartnerQuote> = {
  recent: byDate((r) => r.created_at),
  oldest: byDate((r) => r.created_at, "asc"),
  premium: byNumber((r) => r.best_premium_minor),
  name: byText((r) => r.customer_name),
};
const haystack = (r: PartnerQuote) => [r.customer_name, r.line_code, r.status, r.channel];

export default function BrokerQuotes() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerWorkspaceApi.quotes(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("pcStatus"), options: optionsFrom(rows, (r) => ({ value: outcome(r), label: td(`quoteStatus_${outcome(r)}`, outcome(r)) })) },
      { key: "line", title: t("fltProductFamily"), options: optionsFrom(rows, (r) => ({ value: r.line_code, label: td(`line_${r.line_code}`, r.line_code) })) },
      periodSection(t, "created", t("brCreated")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "premium", label: t("fltSortAmountHigh") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  return (
    <Screen>
      <AppHeader title={t("agQuotes")} subtitle={t("brQuotesSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("quotesLoading")}
        emptyTitle={t("agNoQuotes")}
        emptyMessage={t("brNoQuotesBody")}
      >
        {() => (
          <FilteredList
            list="broker.quotes"
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            initial={filtersFromParams(params)}
            icon={FileSignature}
            onPress={(r) => router.push({ pathname: "/broker/quotes/[id]", params: { id: r.id, title: `${r.customer_name} · ${td(`line_${r.line_code}`, r.line_code)}` } })}
            render={(r) => ({
              title: `${r.customer_name} · ${td(`line_${r.line_code}`, r.line_code)}`,
              subtitle: [
                r.best_premium_minor !== null ? t("brBestOffer", { amount: money(r.best_premium_minor) }) : t("brOffersCount", { count: r.offers }),
                shortDate(r.created_at),
              ].join(" · "),
              status: td(`quoteStatus_${outcome(r)}`, r.status),
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
