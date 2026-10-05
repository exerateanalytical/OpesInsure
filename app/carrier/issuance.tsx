import React, { useCallback } from "react";
import { router, useFocusEffect } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { useCursorList } from "@/hooks/useCursorList";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { CarrierPagedApi } from "@/api/workspace";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/**
 * Issuance queue (CAR-005). Approve / reject moved from the card to the
 * Issuance Detail so the decision is always taken with the full record
 * (proposal, payment verification, approvals) in view. The list refetches
 * on focus so a decision made in the detail is reflected here.
 */
export default function Issuance() {
  return (
    <CarrierGate module="issuance">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  // Phase-1 fix S: cursor-paged (the list used to stop at the first 100 rows).
  const q = useCursorList(() => CarrierPagedApi.issuance());
  const { reload, data } = q;
  useFocusEffect(
    useCallback(() => {
      if (data !== undefined) void reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [reload]),
  );
  return (
    <Screen>
      <AppHeader title={t("caIssuanceQueue")} subtitle={t("caIssuanceSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("caLoadingIssuance")}
        emptyTitle={t("caNothingToIssue")}
        emptyMessage={t("caNothingToIssueBody")}
      >
        {(rows) => (
          <FilteredList
            list="carrier.issuance"
            icon={FileCheck2}
            onPress={({ id }) => router.push(`/carrier/issuance/${id}` as never)}
            rows={rows}
            {...listSpec(rows, t, { status: (r) => r.status, statusLabel: humanize, dims: [{ key: "priority", title: t("fltPriority"), get: (r) => ({ value: r.priority, label: humanize(r.priority) }) }], date: (r) => r.submitted_at, dateTitle: t("fltReceived") })}
            haystack={(r) => [r.reference, r.subject, humanize(r.status)]}
            placeholder={t("fltSearchQueue")}
            render={(r) => ({
              title: r.reference,
              subtitle: `${r.subject} · ${shortDate(r.submitted_at)}`,
              status: humanize(r.status),
            })}
          />
        )}
      </StatePanel>
      <LoadMore {...q.more} />
    </Screen>
  );
}
