import { router } from "expo-router";
import { CalendarClock, PenLine } from "lucide-react-native";
import type { PriorityItem } from "@/components/customer/PriorityFeed";
import { InstalmentsApi, SettlementFlowsApi, type Instalment } from "@/api/customerFlows";
import { canSignDischarge, instalmentAttention, settlementProbeClaims } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";

const MAX_POLICIES = 5;

/**
 * Home "Needs your attention" items for money the customer must act on:
 * instalments overdue or due within a week (active policies, a few at most) and a
 * settlement discharge waiting for their signature. Failures are silent: Home never
 * blocks on these optional calls.
 */
export function useMoneyAttention(policies: { id: string; status?: string | null; policy_number?: string | null }[], claims: { id: string; status?: string | null; claim_number?: string | null }[] | undefined): PriorityItem[] {
  const { t } = useTranslation();
  const f = useFormatters();
  const active = policies.filter((p) => ["ACTIVE", "EXPIRING", "SUSPENDED"].includes(String(p.status ?? "").toUpperCase())).slice(0, MAX_POLICIES);
  const policyKey = active.map((p) => p.id).join(",");
  const probe = settlementProbeClaims(claims ?? []);
  const claimKey = probe.map((c) => c.id).join(",");
  const schedules = useLoad(
    () => Promise.all(active.map((p) => InstalmentsApi.schedule(p.id).then((s) => ({ policy: p, rows: s.data })).catch(() => ({ policy: p, rows: [] as Instalment[] })))),
    [policyKey],
  );
  const settlements = useLoad(
    () => Promise.all(probe.map((c) => SettlementFlowsApi.get(c.id).then((s) => ({ claim: c, s })).catch(() => ({ claim: c, s: null })))),
    [claimKey],
  );
  const items: PriorityItem[] = [];
  for (const { policy, rows } of schedules.data ?? [])
    for (const a of instalmentAttention(rows).slice(0, 1))
      items.push({
        key: `inst-${a.instalment.id}`,
        icon: CalendarClock,
        tint: a.overdue ? "red" : "gold",
        title: a.overdue ? t("instAttentionOverdue") : t("instAttentionDue"),
        body: t("instAttentionBody", { amount: f.xaf(a.instalment.outstanding_minor), date: f.date(a.instalment.due_date), policy: policy.policy_number ?? "" }),
        go: () => router.push({ pathname: "/policy/[id]/instalment-pay", params: { id: policy.id, instalmentId: a.instalment.id } }),
      });
  for (const { claim, s } of settlements.data ?? [])
    if (canSignDischarge(s))
      items.push({
        key: `discharge-${claim.id}`,
        icon: PenLine,
        tint: "gold",
        title: t("dchReviewSign"),
        body: claim.claim_number ?? t("dchReviewSignBody"),
        go: () => router.push(`/claim/${claim.id}/discharge`),
      });
  return items;
}
