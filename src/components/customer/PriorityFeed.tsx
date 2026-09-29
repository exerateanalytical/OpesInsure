import React, { useEffect, useRef, useState } from "react";
import { AccessibilityInfo, Animated, Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { AlertTriangle, CreditCard, FileWarning, IdCard, RefreshCw, type LucideIcon } from "lucide-react-native";
import { Banner, type Tint } from "@/components/design";
import { kycPhase } from "@/lib/kyc";
import { daysUntil, isRenewalDue } from "@/lib/customerLogic";
import type { Payment, Policy, ProposalSummary } from "@/api/client";
import { purchaseRoute } from "@/lib/paymentRouting";
import { useFormatters } from "@/hooks/useFormatters";
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
  /** Applications awaiting payment (paymentRouting.payableApplications): "Pay now" opens the terms. */
  applications?: ProposalSummary[];
};

/**
 * HOME-002/003: urgent, actionable exceptions derived only from server state
 * (a payment is never treated as paid from a client event). Each item links
 * to the record that resolves it and disappears once the server state moves on.
 */
export function usePriorityItems({ payments, claims, policies, kyc, applications }: Input): PriorityItem[] {
  const { t, date } = useTranslation();
  const f = useFormatters();
  const items: PriorityItem[] = [];
  for (const a of applications ?? []) {
    const total = a.terms_snapshot?.total_minor ?? (a as { total_minor?: number }).total_minor;
    items.push({
      key: `apply-${a.id}`,
      icon: CreditCard,
      tint: "green",
      title: t("payApprovedTitle"),
      body: typeof total === "number" && total > 0 ? t("payApprovedBodyAmount", { amount: f.xaf(total) }) : t("payApprovedBody"),
      go: () => router.push(purchaseRoute(a.id, "terms") as never),
    });
  }
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

/** How long each pending item stays on screen before the next one rotates in. */
export const PRIORITY_ROTATE_MS = 5000;

/**
 * The "Needs your attention" block: one item at a time, rotating automatically, with
 * "View all" beside the title to expand the full list. Renders nothing when nothing is pending.
 * Rotation stops while expanded and when the device asks for reduced motion.
 */
export function PriorityFeed({ items }: { items: PriorityItem[] }) {
  const { t } = useTranslation();
  const [index, setIndex] = useState(0);
  const [expanded, setExpanded] = useState(false);
  const [reduceMotion, setReduceMotion] = useState(false);
  const fade = useRef(new Animated.Value(1)).current;
  const count = items.length;

  useEffect(() => {
    let live = true;
    AccessibilityInfo.isReduceMotionEnabled()
      .then((v) => live && setReduceMotion(v))
      .catch(() => {});
    const sub = AccessibilityInfo.addEventListener("reduceMotionChanged", setReduceMotion);
    return () => {
      live = false;
      sub.remove();
    };
  }, []);

  // Items come and go with server state; keep the index in range.
  useEffect(() => {
    if (index >= count) setIndex(0);
  }, [count, index]);

  useEffect(() => {
    if (count < 2 || expanded || reduceMotion) return;
    const timer = setInterval(() => {
      Animated.timing(fade, { toValue: 0, duration: 180, useNativeDriver: true }).start(() => {
        setIndex((i) => (i + 1) % count);
        Animated.timing(fade, { toValue: 1, duration: 220, useNativeDriver: true }).start();
      });
    }, PRIORITY_ROTATE_MS);
    return () => clearInterval(timer);
  }, [count, expanded, reduceMotion, fade]);

  if (!count) return null;
  const shown = Math.min(index, count - 1);
  const current = items[shown]!;

  return (
    <View style={s.wrap}>
      <View style={s.head}>
        <AlertTriangle size={18} color={colors.warningText} />
        <Text accessibilityRole="header" style={s.title}>
          {t("prioTitle", { count })}
        </Text>
        {count > 1 ? (
          <Pressable accessibilityRole="button" hitSlop={8} onPress={() => setExpanded((v) => !v)} style={s.link}>
            <Text style={s.linkText}>{expanded ? t("prioShowLess") : t("prioViewAll")}</Text>
          </Pressable>
        ) : null}
      </View>
      {expanded ? (
        items.map((i) => <Banner key={i.key} icon={i.icon} tint={i.tint} title={i.title} body={i.body} onPress={i.go} />)
      ) : (
        <>
          <Animated.View style={{ opacity: fade }}>
            <Banner key={current.key} icon={current.icon} tint={current.tint} title={current.title} body={current.body} onPress={current.go} />
          </Animated.View>
          {count > 1 ? (
            <View style={s.dots} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
              {items.map((i, n) => (
                <View key={i.key} style={[s.dot, n === shown && s.dotOn]} />
              ))}
            </View>
          ) : null}
        </>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x2 },
  head: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.sectionTitle, color: colors.navy950, flexShrink: 1, flex: 1 },
  link: { minHeight: 32, justifyContent: "center" },
  linkText: { ...type.label, color: colors.blue600 },
  dots: { flexDirection: "row", justifyContent: "center", gap: 6 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: colors.neutral300 },
  dotOn: { width: 16, backgroundColor: colors.blue600 },
});
