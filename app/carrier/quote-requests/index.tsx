import React, { useState } from "react";
import { router } from "expo-router";
import { Inbox } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierQuoteRequestsApi } from "@/api/workflow";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { slaState } from "@/lib/quoteWorkflow";

/** Insurer work queue for manual quotation (REQ-QUO-006), soonest deadline first. */
export default function CarrierQuoteRequests() {
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
          <OperationsList
            icon={Inbox}
            onPress={(id) => router.push({ pathname: "/carrier/quote-requests/[id]", params: { id } })}
            rows={rows.map((r) => {
              const sla = slaState(r);
              return {
                id: r.id,
                title: r.request_number,
                subtitle: [
                  r.response_due_at ? t("cqrDue", { date: f.dateTime(r.response_due_at) }) : null,
                  td(`cqrSla_${sla.state}`, sla.state).replace("{hours}", String(sla.hoursLeft ?? 0)),
                ]
                  .filter(Boolean)
                  .join(" · "),
                status: td(`cqrStatus_${r.status}`, r.status),
              };
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
