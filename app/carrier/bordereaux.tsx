import { router } from "expo-router";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { FileSpreadsheet } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierFinanceApi, fcfa } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";

const day = (v?: string | null) => (v ? formatDisplayDate(v) : "");

export default function CarrierBordereaux() {
  return (
    <CarrierGate module="bordereaux">
      <CarrierBordereauxBody />
    </CarrierGate>
  );
}

function CarrierBordereauxBody() {
  const { t, td } = useTranslation();
  const q = useLoad(() => CarrierFinanceApi.bordereaux(), []);
  return (
    <Screen>
      <AppHeader
        title={t("caBordereaux")}
        subtitle={t("caBordereauxSubtitle")}
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle={t("caNoBordereaux")}
        emptyMessage={t("caNoBordereauxBody")}
      >
        {(x) => (
          <FilteredList
            list="carrier.bordereaux"
            icon={FileSpreadsheet}
            onPress={({ id }) => router.push(`/carrier/bordereaux/${id}` as never)}
            rows={x}
            {...listSpec(x, t, { status: (b) => b.status, dims: [{ key: "type", title: t("fltType"), get: (b) => ({ value: b.type }) }], date: (b) => b.period_end, dateTitle: t("fltPeriod"), amount: (b) => b.gross_premium_minor })}
            haystack={(b) => [b.bordereau_number, b.type, b.status]}
            placeholder={t("fltSearchPlaceholder")}
            amount={(b) => b.gross_premium_minor}
            render={(b) => ({
              title: b.bordereau_number,
              subtitle: `${day(b.period_start)} – ${day(b.period_end)} · ${t(b.item_count === 1 ? "caItemsCountOne" : "caItemsCount", { count: b.item_count })} · ${fcfa(b.gross_premium_minor)}`,
              statusCode: b.status,
              status: td(`bdxStatus_${b.status}`, b.status),
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
