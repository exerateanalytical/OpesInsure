import React, { useMemo } from "react";
import { ShieldAlert, ShieldPlus } from "lucide-react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button } from "@/components/ui";
import { FilteredList } from "@/components/partner/FilteredList";
import { usePermission } from "@/components/carrier/CarrierGate";
import { byDate, byNumber, filtersFromParams, optionsFrom, periodMatcher, periodSection, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { BrokerWorkspaceApi, money, shortDate, type PartnerClaim } from "@/api/partner";
import { useTranslation } from "@/i18n";

const matchers: Matchers<PartnerClaim> = {
  status: (c, v) => c.status === v,
  priority: (c, v) => c.priority === v,
  carrier: (c, v) => (c.carrier_name ?? "") === v,
  submitted: periodMatcher((c) => c.submitted_at),
};
const sorters: Sorters<PartnerClaim> = {
  recent: byDate((c) => c.submitted_at),
  oldest: byDate((c) => c.submitted_at, "asc"),
  amount: byNumber((c) => c.approved_amount_minor ?? c.estimated_loss_minor),
};
const haystack = (c: PartnerClaim) => [c.claim_number, c.customer_name, c.policy_number, c.carrier_name];

export default function BrokerClaims() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  // POST /mobile/partner/broker/claims requires broker.claims.file (server-enforced too).
  const canReport = usePermission("broker.claims.file");
  const q = useLoad(() => BrokerWorkspaceApi.claims(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("fltClaimState"), options: optionsFrom(rows, (c) => ({ value: c.status, label: td(`claimStatus_${c.status}`, c.status) })) },
      { key: "priority", title: t("bkPriority"), options: optionsFrom(rows, (c) => (c.priority ? { value: c.priority, label: td(`priority_${c.priority}`, c.priority) } : null)) },
      { key: "carrier", title: t("fltInsurer"), options: optionsFrom(rows, (c) => ({ value: c.carrier_name, label: c.carrier_name })) },
      periodSection(t, "submitted"),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "amount", label: t("fltSortAmountHigh") },
      ]),
    ],
    [rows, t, td],
  );
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("claims")} subtitle={t("brClaimsSubtitle")} />
      {canReport ? <Button icon={ShieldPlus} label={t("brReportClaim")} hint={t("pdFileClaimHint")} onPress={() => router.push("/broker/claims/new")} /> : null}
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("brNoClaims")}
        emptyMessage={t("brNoClaimsBody")}
      >
        {() => (
          <FilteredList
            list="broker.claims"
            rows={rows}
            sections={sections}
            matchers={matchers}
            haystack={haystack}
            sorters={sorters}
            initial={filtersFromParams(params)}
            icon={ShieldAlert}
            mark={(c) => (c.carrier_name || c.carrier_logo_url ? { logoUrl: c.carrier_logo_url, name: c.carrier_name } : null)}
            onPress={(c) => router.push({ pathname: "/broker/claims/[id]", params: { id: c.id } })}
            render={(c) => ({
              title: `${c.claim_number} · ${c.customer_name}`,
              subtitle: [
                c.policy_number,
                c.carrier_name,
                c.approved_amount_minor !== null
                  ? `${t("bkApprovedAmount")} ${money(c.approved_amount_minor)}`
                  : c.estimated_loss_minor !== null
                    ? `${t("bkEstimatedLoss")} ${money(c.estimated_loss_minor)}`
                    : null,
                `${t("bkFiled")} ${shortDate(c.submitted_at)}`,
              ]
                .filter(Boolean)
                .join(" · "),
              status: td(`claimStatus_${c.status}`, c.status),
            })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
