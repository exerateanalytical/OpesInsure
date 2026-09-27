import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { LifeBuoy, WalletCards } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton, HeritageAccent } from "@/components/agent";
import { AgentApi, type AgentWithdrawal } from "@/api/client";
import { money } from "@/api/partner";
import { isWithdrawalTerminalFailure, maskPhone, providerLabel, WITHDRAWAL_FLOW, withdrawalReached, withdrawalVocab } from "@/components/partner/agentEarnings";
import { KV, Timeline, WithdrawalChip, withdrawalWord } from "@/components/partner/AgentEarningsUi";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/** Optional fields the server may add; each one renders only when present. */
type WithdrawalExtra = AgentWithdrawal & {
  reference?: string | null;
  payout_number?: string | null;
  failure_reason?: string | null;
  reason?: string | null;
  transaction_reference?: string | null;
  estimated_settlement_at?: string | null;
  estimated_settlement_text?: string | null;
  reviewed_at?: string | null;
  processing_at?: string | null;
  paid_at?: string | null;
  failed_at?: string | null;
};

/** AGT-006: one withdrawal of the signed-in agent's partner (server-scoped list). Spec v2 screen 08. */
export default function AgentWithdrawalDetail() {
  const { t, td } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => ((await AgentApi.withdrawals()).find((w) => w.id === id) ?? null) as WithdrawalExtra | null, [id]);
  const w = q.data;
  const word = (s: Parameters<typeof withdrawalWord>[0]) => td(`agentSt_${withdrawalWord(s).replace(/\s+/g, "")}`, withdrawalWord(s));
  return (
    <AgentShell variant="drilldown" title={t("ernWdTitle")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} height={56} />
      ) : q.error ? (
        <AgentEmptyState icon={WalletCards} title={t("ernLoadError")} body={t("loadErrorBody")} actionLabel={t("ernRetry")} onAction={q.reload} />
      ) : !w ? (
        <AgentEmptyState icon={WalletCards} title={t("pdNotFound")} body={t("ernNotFound")} />
      ) : (
        (() => {
          const v = withdrawalVocab(w.status);
          const failed = isWithdrawalTerminalFailure(v);
          const reached = withdrawalReached(v);
          const ref = w.payout_number ?? w.reference ?? w.id.slice(0, 8).toUpperCase();
          const stamps = [w.requested_at, w.reviewed_at, w.processing_at, w.paid_at];
          const steps: Parameters<typeof Timeline>[0]["steps"] = failed
            ? [
                { label: word("requested"), date: formatDisplayDate(w.requested_at, true), state: "done" },
                { label: word(v), date: w.failed_at ? formatDisplayDate(w.failed_at, true) : null, state: "failed", note: w.failure_reason ?? w.reason ?? t("ernWdFailedBody") },
              ]
            : WITHDRAWAL_FLOW.map((st, i) => ({
                label: word(st),
                date: stamps[i] ? formatDisplayDate(stamps[i], true) : null,
                state: i < reached || (i === reached && v === "paid") ? "done" : i === reached ? "current" : "todo",
              }));
          const settlement = w.estimated_settlement_at ? formatDisplayDate(w.estimated_settlement_at, true) : w.estimated_settlement_text ?? null;
          return (
            <View style={s.wrap}>
              <View style={s.hero}>
                <HeritageAccent variant="pattern" size={180} opacity={0.06} style={s.art} />
                <WithdrawalChip status={w.status} />
                <Text style={s.amount} numberOfLines={1} adjustsFontSizeToFit>{money(w.amount_minor)}</Text>
              </View>
              <AgentCard>
                <KV first label={t("ernWdId")} value={ref} />
                <KV label={t("ernWdMethod")} value={providerLabel(w.provider)} />
                <KV label={t("ernWdAccount")} value={maskPhone(w.destination_phone)} />
                <KV label={t("ernWdRequested")} value={formatDisplayDate(w.requested_at, true)} />
                {settlement && !failed && v !== "paid" ? <KV label={t("ernWdSettlement")} value={settlement} /> : null}
                {w.transaction_reference ? <KV label={t("wdTransaction")} value={w.transaction_reference} /> : null}
              </AgentCard>
              <AgentSection title={t("ernWdStatusTimeline")}>
                <AgentCard>
                  <Timeline steps={steps} />
                </AgentCard>
              </AgentSection>
              <AgentButton
                variant="secondary"
                icon={LifeBuoy}
                label={t("ernWdContact")}
                onPress={() =>
                  router.push({
                    pathname: "/support/new",
                    params: {
                      category: "PAYMENT",
                      reference: ref,
                      subject: t("ernWdSupportSubject", { ref }),
                      body: t("ernWdSupportBody", { ref, amount: money(w.amount_minor), date: formatDisplayDate(w.requested_at, true) }),
                    },
                  })
                }
              />
              <Text style={s.notice}>{t("wdNoFeeNotice")}</Text>
            </View>
          );
        })()
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  wrap: { gap: L.sectionGap },
  hero: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, padding: 20, gap: 8, alignItems: "flex-start", overflow: "hidden" },
  art: { position: "absolute", right: -30, top: -30 },
  amount: { ...T.heroAmount, color: c.heading },
  notice: { ...T.caption, color: c.muted },
});
