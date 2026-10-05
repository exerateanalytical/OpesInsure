import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { useCursorList } from "@/hooks/useCursorList";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { CarrierPagedApi } from "@/api/workspace";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ClipboardCheck } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { useTranslation } from "@/i18n";
export default function Referrals() {
  return (
    <CarrierGate module="referrals">
      <ReferralsBody />
    </CarrierGate>
  );
}

function ReferralsBody() {
  const { t } = useTranslation();
  // Phase-1 fix S: cursor-paged (the list used to stop at the first 100 rows).
  const q = useCursorList(() => CarrierPagedApi.referrals());
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title={t("caReferrals")}
        subtitle={t("caReferralsSubtitle")}
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <FilteredList
            list="carrier.referrals"
            icon={ClipboardCheck}
            rows={x}
            {...listSpec(x, t, { status: (r) => r.status, dims: [{ key: "product", title: t("fltProductLine"), get: (r) => ({ value: r.product }) }], date: (r) => r.submitted_at, dateTitle: t("fltReceived"), name: (r) => r.customer_name })}
            haystack={(r) => [r.customer_name, r.product, r.reason, r.status]}
            placeholder={t("fltSearchQueue")}
            render={(r) => ({
              title: r.customer_name,
              subtitle: `${r.product} · ${r.reason}`,
              status: r.status,
            })}
            onPress={({ id }) => router.push(`/carrier/referrals/${id}`)}
          />
          </>
        )}
      </StatePanel>
      <LoadMore {...q.more} />
    </PortalScreen>
  );
}
