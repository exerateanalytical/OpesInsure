import React from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Card, Money, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { Step } from "@/components/FlowPrimitives";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AgentApi } from "@/api/client";
import { humanize } from "@/api/partner";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const FLOW = ["REQUESTED", "APPROVED", "PROCESSING", "PAID"] as const;
const FAILED = new Set(["FAILED", "REJECTED", "CANCELLED", "REVERSED"]);

/** AGT-006: one withdrawal of the signed-in agent's partner (server-scoped list). */
export default function AgentWithdrawalDetail() {
  const { t, td } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => (await AgentApi.withdrawals()).find((w) => w.id === id) ?? null, [id]);
  return (
    <Screen>
      <AppHeader title={t("wdDetailTitle")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(w) => {
          if (!w) return <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />;
          const x = w as typeof w & { reference?: string | null; failure_reason?: string | null; transaction_reference?: string | null; paid_at?: string | null };
          const reached = FLOW.indexOf(w.status as (typeof FLOW)[number]);
          return (
            <>
              <Card feature>
                <StatusChip label={td(`withdrawalStatus_${w.status}`, humanize(w.status))} tone={w.status === "PAID" ? "success" : FAILED.has(w.status) ? "danger" : "warning"} />
                <Money amount={w.amount_minor / 100} size="large" />
                <DetailRow label={t("wdReference")} value={x.reference ?? w.id.slice(0, 8).toUpperCase()} />
                <DetailRow label={t("wdDestination")} value={`${w.provider === "orange_money" ? "Orange Money" : w.provider === "mtn_momo" ? "MTN MoMo" : w.provider} · ${w.destination_phone}`} />
                <DetailRow label={t("wdRequested")} value={formatDisplayDate(w.requested_at, true)} />
                <DetailRow label={t("wdTransaction")} value={x.transaction_reference} />
                <DetailRow label={t("wdPaidAt")} value={x.paid_at ? formatDisplayDate(x.paid_at, true) : null} />
              </Card>
              <SectionTitle title={t("wdProgress")} />
              <Card>
                {FAILED.has(w.status) ? (
                  <Text style={{ ...type.body, color: colors.dangerText }}>{x.failure_reason ?? t("wdFailedBody")}</Text>
                ) : (
                  FLOW.map((s, i) => <Step key={s} label={td(`withdrawalStatus_${s}`, humanize(s))} complete={reached >= i} />)
                )}
              </Card>
              <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("wdNoFeeNotice")}</Text>
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
