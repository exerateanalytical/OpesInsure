import React, { useMemo } from "react";
import { router } from "expo-router";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { byDate, byNumber, byText, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { PartnerProposal, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { FilteredList } from "./FilteredList";
import { AgentShell } from "@/components/agent";
import { BookLoad, bookStyles } from "./AgentBookUi";
import { Text } from "react-native";
import { saleCommissionLine } from "./SaleCommission";
import type { CommissionRow } from "./commissionFilters";

const when = (p: PartnerProposal) => p.submitted_at ?? p.created_at;
const matchers: Matchers<PartnerProposal> = {
  status: (p, v) => p.status === v,
  carrier: (p, v) => (p.carrier_name ?? "") === v,
  line: (p, v) => (p.line_code ?? "") === v,
  submitted: periodMatcher(when),
};
const sorters: Sorters<PartnerProposal> = {
  recent: byDate(when),
  oldest: byDate(when, "asc"),
  amount: byNumber((p) => p.total_minor),
  name: byText((p) => p.customer_name),
};
const haystack = (p: PartnerProposal) => [p.proposal_number, p.customer_name, p.carrier_name, p.line_code, p.status];

/** Book proposals list shared by /agent/proposals and /broker/proposals. */
export function PartnerProposalsScreen({
  tabs,
  load,
  portal,
  loadCommissions,
}: {
  /** NAV-001: rows open the issued policy, else the client record, in this portal. */
  portal: "agent" | "broker";
  tabs: React.ComponentProps<typeof PortalScreen>["tabs"];
  load: () => Promise<PartnerProposal[]>;
  /** Commission ledger: issued proposals show the commission the server posted for that sale. */
  loadCommissions?: () => Promise<CommissionRow[]>;
}) {
  const { t, td } = useTranslation();
  const q = useLoad(load, []);
  const ledger = useLoad(async () => (loadCommissions ? await loadCommissions().catch(() => null) : null), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("pcStatus"), options: optionsFrom(rows, (p) => ({ value: p.status, label: td(`proposalStatus_${p.status}`, humanize(p.status)) })) },
      { key: "carrier", title: t("fltInsurer"), options: optionsFrom(rows, (p) => ({ value: p.carrier_name, label: p.carrier_name })) },
      { key: "line", title: t("fltProductFamily"), options: optionsFrom(rows, (p) => (p.line_code ? { value: p.line_code, label: td(`line_${p.line_code}`, p.line_code) } : null)) },
      periodSection(t, "submitted"),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "amount", label: t("fltSortAmountHigh") },
        { value: "name", label: t("fltSortName") },
      ]),
    ],
    [rows, t, td],
  );
  const subtitle = (p: PartnerProposal) =>
    [
      p.carrier_name,
      shortDate(when(p)),
      // Commission per sale once issued (policy id); before issuance only a server estimate would show.
      p.policy_id ? saleCommissionLine(ledger.data, { policyId: p.policy_id, proposalId: p.id }, t) : null,
    ]
      .filter(Boolean)
      .join(" · ");
  const open = (p: PartnerProposal) => {
    if (p.policy_id) router.push(`/${portal}/policies/${p.policy_id}` as never);
    else if (p.customer_id) router.push(`/${portal}/clients/${p.customer_id}` as never);
  };
  if (portal === "agent") {
    // Commercial Agent spec v2: drill-down shell (proposals is not a bottom-nav tab), kit rows and states.
    return (
      <AgentShell variant="drilldown" title={t("ptProposals")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
        <Text style={bookStyles.body}>{t("ptProposalsSubtitle")}</Text>
        <BookLoad q={q} icon={FileSignature} emptyTitle={t("ptNoProposals")} emptyBody={t("ptNoProposalsBody")}>
          {() => (
            <FilteredList
              variant="agent"
              list="agent.proposals"
              rows={rows}
              sections={sections}
              matchers={matchers}
              haystack={haystack}
              sorters={sorters}
              icon={FileSignature}
              amount={(p) => p.total_minor}
              onPress={open}
              render={(p) => ({
                title: p.customer_name,
                subtitle: [p.proposal_number, subtitle(p)].filter(Boolean).join(" · "),
                amount: p.total_minor !== null ? money(p.total_minor) : null,
                statusCode: p.status,
                status: td(`proposalStatus_${p.status}`, humanize(p.status)),
              })}
            />
          )}
        </BookLoad>
      </AgentShell>
    );
  }
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title={t("ptProposals")} subtitle={t("ptProposalsSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("ptProposalsLoading")}
        emptyTitle={t("ptNoProposals")}
        emptyMessage={t("ptNoProposalsBody")}
      >
        {() => (
          <FilteredList
            list={`${portal}.proposals`}
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            icon={FileSignature}
            mark={(p) => (p.carrier_name || p.carrier_logo_url ? { logoUrl: p.carrier_logo_url, name: p.carrier_name } : null)}
            amount={(p) => p.total_minor}
            onPress={(p) => {
              if (p.policy_id) router.push(`/${portal}/policies/${p.policy_id}` as never);
              else if (p.customer_id) router.push(`/${portal}/clients/${p.customer_id}` as never);
            }}
            render={(p) => ({
              title: `${p.proposal_number} · ${p.customer_name}`,
              subtitle: [
                p.carrier_name,
                p.total_minor !== null ? money(p.total_minor) : null,
                shortDate(when(p)),
                // Commission per sale once issued (policy id); before issuance only a server estimate would show.
                p.policy_id ? saleCommissionLine(ledger.data, { policyId: p.policy_id, proposalId: p.id }, t) : null,
              ]
                .filter(Boolean)
                .join(" · "),
              status: td(`proposalStatus_${p.status}`, p.status),
            })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
