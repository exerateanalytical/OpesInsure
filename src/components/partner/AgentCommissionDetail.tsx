import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { ArrowUpRight, FileText } from "lucide-react-native";
import { AgentButton, AgentCard, AgentSection, HeritageAccent } from "@/components/agent";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";
import { owedAmount, type CommissionRow } from "./commissionFilters";
import { calculationLines, commissionVocab } from "./agentEarnings";
import { CommissionChip, KV, Timeline } from "./AgentEarningsUi";

/**
 * Commission Detail, Commercial Agent v2 (screen 07). Server values only:
 * a missing figure reads "—", an unpriced (estimated) commission reads
 * "Pending calculation". Rendered by CommissionDetail variant="agent".
 */
export function AgentCommissionDetail({ row, onOpenPolicy, onWithdraw }: { row: CommissionRow; onOpenPolicy?: (policyId: string) => void; onWithdraw?: () => void }) {
  const { t, td } = useTranslation();
  const v = commissionVocab(row);
  const calc = calculationLines(row);
  const owed = owedAmount(row);
  const pending = t("ernPendingCalc");
  const product = row.product_name ?? (row.line_code ? td(`line_${row.line_code}`, humanize(row.line_code)) : null);
  const reversed = v === "reversed";
  const steps: Parameters<typeof Timeline>[0]["steps"] = [
    { label: t("ernTlIssued"), date: row.issued_at ? shortDate(row.issued_at) : null, state: row.issued_at ? "done" : "todo" },
    { label: t("ernTlAccrued"), date: row.accrued_at ? shortDate(row.accrued_at) : null, state: row.accrued_at || v !== "pending" ? "done" : "current" },
    {
      label: t("ernTlAvailable"),
      date: row.available_at ?? row.payable_at ? shortDate(row.available_at ?? row.payable_at) : null,
      state: v === "available" || v === "paid" ? "done" : v === "accrued" ? "current" : "todo",
    },
    { label: t("ernTlPaid"), date: row.paid_at ? shortDate(row.paid_at) : null, state: v === "paid" ? "done" : v === "available" ? "current" : "todo" },
  ];
  if (reversed) steps.push({ label: td("agentSt_Reversed", "Reversed"), state: "failed", note: row.reason ?? null });
  const refs = [
    [t("ernCommissionRef"), row.id],
    [t("ernSettlementBatch"), row.statement_number],
    [t("ernRuleVersion"), row.rule_version],
  ].filter(([, x]) => !!x) as [string, string][];
  return (
    <View style={s.wrap}>
      <View style={s.hero}>
        <HeritageAccent variant="pattern" size={180} opacity={0.06} style={s.art} />
        <CommissionChip row={row} />
        <Text style={s.amount} numberOfLines={1} adjustsFontSizeToFit>{calc.estimated ? pending : money(row.amount_minor)}</Text>
        {owed > 0 && owed !== row.amount_minor ? <Text style={s.meta}>{t("ernOutstanding", { amount: money(owed) })}</Text> : null}
      </View>

      <AgentSection title={t("ernPolicyInfo")}>
        <AgentCard>
          <KV first label={t("ernPolicyNumber")} value={row.policy_number} />
          <KV label={t("ernCustomer")} value={row.customer_name} />
          <KV label={t("ernInsurer")} value={row.carrier_name} />
          <KV label={t("ernProduct")} value={product} />
          <KV label={t("ernPolicyStatus")} value={row.policy_status ? td(`policyStatus_${row.policy_status}`, humanize(row.policy_status)) : null} />
          {row.producer_name ? <KV label={t("ernSeller")} value={row.producer_name} /> : null}
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("ernCalc")}>
        <AgentCard>
          <KV first label={t("ernPremium")} value={calc.premium != null ? money(calc.premium) : null} />
          <KV label={t("ernRate")} value={calc.rate_bps != null ? `${(calc.rate_bps / 100).toFixed(2)} %` : null} />
          <KV label={t("ernGross")} value={calc.gross != null ? money(calc.gross) : pending} />
          <KV label={t("ernAdjustments")} value={calc.adjustments != null ? `− ${money(-calc.adjustments)}` : null} />
          <KV label={t("ernNet")} value={calc.net != null ? money(calc.net) : pending} strong />
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("ernTimeline")}>
        <AgentCard>
          <Timeline steps={steps} />
        </AgentCard>
      </AgentSection>

      {refs.length ? (
        <AgentSection title={t("ernReferences")}>
          <AgentCard>
            {refs.map(([label, value], i) => (
              <KV key={label} first={i === 0} label={label} value={value} />
            ))}
          </AgentCard>
        </AgentSection>
      ) : null}

      <View style={s.actions}>
        {row.policy_id && onOpenPolicy ? <AgentButton variant="secondary" icon={FileText} label={t("ernViewPolicy")} onPress={() => onOpenPolicy(row.policy_id!)} /> : null}
        {onWithdraw && v === "available" && owed > 0 ? <AgentButton icon={ArrowUpRight} label={t("ernRequestWithdrawal")} onPress={onWithdraw} /> : null}
      </View>
      <Text style={s.notice}>{t("pcAuditNotice")}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: L.sectionGap },
  hero: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, padding: 20, gap: 8, alignItems: "flex-start", overflow: "hidden" },
  art: { position: "absolute", right: -30, top: -30 },
  amount: { ...T.heroAmount, color: c.heading },
  meta: { ...T.secondary, color: c.secondary },
  actions: { gap: 12 },
  notice: { ...T.caption, color: c.muted },
});
