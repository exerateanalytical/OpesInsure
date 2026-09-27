import React, { useCallback } from "react";
import { router, useFocusEffect } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { CarrierApi } from "@/api/client";
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
  const q = useLoad(() => CarrierApi.issuance(), []);
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
          <OperationsList
            icon={FileCheck2}
            onPress={(id) => router.push(`/carrier/issuance/${id}` as never)}
            rows={rows.map((r) => ({
              id: r.id,
              title: r.reference,
              subtitle: `${r.subject} · ${shortDate(r.submitted_at)}`,
              status: humanize(r.status),
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
