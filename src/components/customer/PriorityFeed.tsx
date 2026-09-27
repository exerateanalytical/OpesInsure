import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { AlertTriangle, CreditCard, FileWarning, IdCard, RefreshCw, type LucideIcon } from "lucide-react-native";
import { Banner, type Tint } from "@/components/design";
import { kycPhase } from "@/lib/kyc";
import { daysUntil, isRenewalDue } from "@/lib/customerLogic";
import type { Payment, Policy } from "@/api/client";
import type { KycState } from "@/api/customer";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export type PriorityItem = {
  key: string;
  icon: LucideIcon;
  tint: Tint;
  title: string;
  body: string;
  go: () => void;
};

const FAILED = new Set(["FAILED", "EXPIRED"]);
const AWAITING = new Set(["CREATED", "PENDING_CUSTOMER"]);

type Input = {
  payments: Payment[];
  claims: { id: string; status: string; claim_number?: string | null }[];
  policies: Policy[];
  kyc: KycState | null | undefined;
};

/**
 * HOME-002/003: urgent, actionable exceptions derived only from server state
 * (a payment is never treated as paid from a client event). Each item links
 * to the record that resolves it and disappears once the server state moves on.
 */
export function usePriorityItems({ payments, claims, policies, kyc }: Input): PriorityItem[] {
  const { t, date } = useTranslation();
  const items: PriorityItem[] = [];
  // Only the latest attempt per proposal counts: a failure followed by a success is resolved.
  const latest = new Map<string, Payment>();
  for (const p of payments) {
    const k = p.proposal_id ?? p.id;
    const cur = latest.get(k);
    if (!cur || String(p.created_at ?? "") > String(cur.created_at ?? "")) latest.set(k, p);
  }
  for (const p of latest.values()) {
    const s = String(p.status).toUpperCase();
    if (FAILED.has(s))
      items.push({
        key: `pay-${p.id}`,
        icon: CreditCard,
        tint: "red",
        title: t("prioPaymentFailed"),
        body: t("prioPaymentFailedBody"),
        go: () => router.push({ pathname: "/payments/[id]", params: { id: p.id } }),
      });
    else if (AWAITING.has(s))
      items.push({
        key: `pay-${p.id}`,
        icon: CreditCard,
        tint: "gold",
        title: t("prioPaymentAwaiting"),
        body: t("prioPaymentAwaitingBody"),
        go: () => router.push({ pathname: "/payments/[id]", params: { id: p.id } }),
      });
  }
  for (const c of claims)
    if (String(c.status).toUpperCase() === "EVIDENCE_PENDING")
      items.push({
        key: `claim-${c.id}`,
        icon: FileWarning,
        tint: "gold",
        title: t("prioClaimEvidence"),
        body: c.claim_number ?? t("prioClaimEvidenceBody"),
        go: () => router.push({ pathname: "/claim/[id]", params: { id: c.id } }),
      });
  for (const p of policies.filter((x) => isRenewalDue(x)).slice(0, 2))
    items.push({
      key: `renew-${p.id}`,
      icon: RefreshCw,
      tint: "gold",
      title: t("prioPolicyExpiring", { number: p.policy_number }),
      body: t("prioPolicyExpiringBody", { days: daysUntil(p.coverage_ends_at) ?? 0, date: date(p.coverage_ends_at) }),
      go: () => router.push({ pathname: "/policy/[id]/renew", params: { id: p.id } }),
    });
  const sub = kyc?.submission;
  if (kyc && sub) {
    const phase = kycPhase(sub.status, sub.expires_at).phase;
    if (phase === "more_info" || phase === "rejected" || phase === "expired" || phase === "draft")
      items.push({
        key: "kyc",
        icon: IdCard,
        tint: phase === "draft" ? "blue" : "red",
        title: t("prioKyc"),
        body: sub.remediation_reason ?? t("prioKycBody"),
        go: () => router.push("/onboarding/kyc"),
      });
  }
  return items;
}

/** The "Needs your attention" block; renders nothing when there is nothing urgent. */
export function PriorityFeed({ items }: { items: PriorityItem[] }) {
  const { t } = useTranslation();
  if (!items.length) return null;
  return (
    <View style={s.wrap}>
      <View style={s.head}>
        <AlertTriangle size={18} color={colors.warningText} />
        <Text accessibilityRole="header" style={s.title}>
          {t("prioTitle", { count: items.length })}
        </Text>
      </View>
      {items.slice(0, 4).map((i) => (
        <Banner key={i.key} icon={i.icon} tint={i.tint} title={i.title} body={i.body} onPress={i.go} />
      ))}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x2 },
  head: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.sectionTitle, color: colors.navy950, flexShrink: 1 },
});
