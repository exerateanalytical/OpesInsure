import React from "react";
import { useLocalSearchParams } from "expo-router";
import { DetailScreen, DetailSection, UnavailableSection, useListRecord } from "@/components/detail";
import { BrokerApi } from "@/api/client";
import { humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** BRK-009 compliance item (licence or open case). */
export default function BrokerComplianceDetail() {
  const { t, td } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(BrokerApi.compliance, id);
  return (
    <DetailScreen title={t("brCompliance")} subtitle={(c) => c?.label} query={q} isMissing={(c) => c === null}>
      {(c) =>
        c ? (
          <>
            <DetailSection
              title={t("brComplianceShort")}
              rows={[
                [t("pcStatus"), humanize(c.status)],
                [t("bkItem"), c.label],
                [t("bkDue"), shortDate(c.due_at)],
                [t("bkSeverity"), td(`severity_${c.severity}`, c.severity)],
              ]}
            />
            <UnavailableSection title={t("bkEvidence")} message={t("bkComplianceEvidencePending")} />
          </>
        ) : null
      }
    </DetailScreen>
  );
}
