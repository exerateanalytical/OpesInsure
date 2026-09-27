import { CarrierGate } from "@/components/carrier/CarrierGate";
import React, { useState } from "react";
import { router } from "expo-router";
import { Inbox } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierQuoteRequestsApi } from "@/api/workflow";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { caseWaitingState, slaChipText } from "@/lib/quoteWorkflow";

/** Insurer work queue for manual quotation (REQ-QUO-006), soonest deadline first. */
export default function CarrierQuoteRequests() {
  return (
    <CarrierGate module="quote_requests">
      <CarrierQuoteRequestsBody />
    </CarrierGate>
  );
}

function CarrierQuoteRequestsBody() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const [open, setOpen] = useState(true);
  const q = useLoad(() => CarrierQuoteRequestsApi.list(open), [open]);
  return (
    <Screen>
      <AppHeader title={t("cqrTitle")} subtitle={t("cqrSubtitle")} back />
      <Button label={open ? t("cqrShowAll") : t("cqrShowOpen")} variant="tertiary" onPress={() => setOpen((x) => !x)} />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("cqrLoading")} emptyTitle={t("cqrEmpty")} emptyMessage={t("cqrEmptyBody")}>
        {(rows) => (
          <FilteredList
            list={`carrier.quoteRequests.${open ? "open" : "all"}`}
            icon={Inbox}
            onPress={({ id }) => router.push({ pathname: "/carrier/quote-requests/[id]", params: { id } })}
            rows={rows}
            {...listSpec(rows, t, {
              status: (r) => r.status,
              statusLabel: (v) => td(`cqrStatus_${v}`, v),
              date: (r) => r.response_due_at,
              dateTitle: t("fltDueDate"),
            })}
            haystack={(r) => [r.request_number, td(`cqrStatus_${r.status}`, r.status)]}
            placeholder={t("fltSearchQueue")}
            render={(r) => {
              const waiting = caseWaitingState(r);
              return {
                title: r.request_number,
                subtitle: [
                  r.response_due_at ? t("cqrDue", { date: f.dateTime(r.response_due_at) }) : null,
                  slaChipText(r, td),
                ]
                  .filter(Boolean)
                  .join(" · "),
                status: waiting ? td(`cqrWait_${waiting}`, waiting) : td(`cqrStatus_${r.status}`, r.status),
              };
            }}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
