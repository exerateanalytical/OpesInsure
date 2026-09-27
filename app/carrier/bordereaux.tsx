import { router } from "expo-router";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { FileSpreadsheet } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
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
  const { t } = useTranslation();
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
          <OperationsList
            icon={FileSpreadsheet}
            onPress={(id) => router.push(`/carrier/bordereaux/${id}` as never)}
            rows={x.map((b) => ({
              id: b.id,
              title: b.bordereau_number,
              subtitle: `${day(b.period_start)} – ${day(b.period_end)} · ${b.item_count} items · ${fcfa(b.gross_premium_minor)}`,
              status: b.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
