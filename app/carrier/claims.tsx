import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { ShieldAlert } from "lucide-react-native";
import { router } from "expo-router";
import { AppHeader } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function CarrierClaims() {
  return (
    <CarrierGate module="claims">
      <CarrierClaimsBody />
    </CarrierGate>
  );
}

function CarrierClaimsBody() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.claims(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title={t("claims")}
        subtitle={t("caClaimsSubtitle")}
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("caNoClaims")}
        emptyMessage={t("caNoClaimsBody")}
      >
        {() => (
          <>
          <FilteredList
            list="carrier.claims"
            icon={ShieldAlert}
            onPress={({ id }) => router.push(`/carrier/claims/${id}`)}
            rows={x}
            {...listSpec(x, t, { status: (i) => i.status, dims: [{ key: "priority", title: t("fltPriority"), get: (i) => ({ value: i.priority }) }], date: (i) => i.submitted_at, dateTitle: t("fltReceived") })}
            haystack={(i) => [i.reference, i.subject, i.priority, i.status, i.carrier_name]}
            placeholder={t("fltSearchQueue")}
            render={(i) => ({
              title: i.reference,
              subtitle: `${i.subject} · ${i.priority}`,
              status: i.status,
            })}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}
