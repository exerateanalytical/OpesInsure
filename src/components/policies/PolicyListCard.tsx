import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronRight, LucideIcon } from "lucide-react-native";
import { CATEGORIES } from "@/components/customer/categories";
import { CATEGORY_TINT } from "@/components/customer/CategoryTiles";
import { InstitutionMark } from "@/components/InstitutionMark";
import { carrierMark, useCarriers } from "@/components/customer/useCarriers";
import { ripple, StatusChip } from "@/components/ui";
import type { Policy, WalletPolicy } from "@/api/client";
import { useFormatters } from "@/hooks/useFormatters";
import { policyStatusInfo } from "@/lib/purchase";
import { insuredObjectLabel } from "@/lib/renewal";
import { daysUntil } from "@/lib/customerLogic";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/** Category for a policy from its product line / name, else null. */
export function policyCategory(p: Policy | WalletPolicy) {
  const w = p as WalletPolicy;
  const line = (w.terms_snapshot as { line_code?: string } | null | undefined)?.line_code ?? (w as { line_code?: string }).line_code ?? "";
  const hay = `${line} ${w.product_name ?? ""}`.toUpperCase();
  return CATEGORIES.find((c) => c.id !== "more" && (c.lines.some((l) => hay.includes(l)) || hay.includes(c.id.toUpperCase()))) ?? null;
}

/**
 * Policy row card from the "My Policies" design: tinted line icon (or image),
 * product title, provider with logo, policy number; on the right the status
 * chip and the renewal / start / expiry date; chevron.
 */
export function PolicyListCard({ policy, onPress }: { policy: WalletPolicy | Policy; onPress?: () => void }) {
  const f = useFormatters();
  const { t } = useTranslation();
  const w = policy as WalletPolicy;
  const info = policyStatusInfo(policy.status, f.language);
  const carriers = useCarriers();
  const mark = carrierMark(carriers, policy.carrier_id ?? w.carrier?.id, {
    name: w.carrier_name ?? w.carrier?.party?.display_name,
    logoUrl: w.carrier_logo_url,
  });
  const provider = mark.name ?? t("licensedCarrier");
  // Insured vehicle / property (wallet risk_asset, else the rated risk facts).
  const asset = insuredObjectLabel(w);
  const cat = policyCategory(policy);
  const Icon: LucideIcon | null = cat?.icon ?? null;
  const tint = cat ? CATEGORY_TINT[cat.id] : { bg: colors.blue50, fg: colors.navy900 };
  const days = daysUntil(policy.coverage_ends_at);
  const dateLabel =
    info.bucket === "active" && days != null && days >= 0
      ? days === 1 ? t("policyRenewsInOne") : t("policyRenewsInDays", { days })
      : info.bucket === "active"
      ? t("policyRenewsOn", { date: f.date(policy.coverage_ends_at) })
      : info.bucket === "pending"
        ? t("policyStartsOn", { date: f.date(policy.coverage_starts_at) })
        : info.bucket === "expired"
          ? t("policyExpiredOn", { date: f.date(policy.coverage_ends_at) })
          : f.range(policy.coverage_starts_at, policy.coverage_ends_at);
  return (
    <Pressable
      accessibilityRole={onPress ? "button" : undefined}
      accessibilityLabel={`${w.product_name ?? t("insurancePolicy")}. ${provider}. ${info.label}. ${dateLabel}`}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <View style={[styles.thumb, { backgroundColor: tint.bg }]}>{Icon ? <Icon size={28} color={tint.fg} /> : null}</View>
      <View style={styles.flex}>
        <Text style={styles.title} >{w.product_name ?? t("insurancePolicy")}</Text>
        <View style={styles.providerRow}>
          <InstitutionMark logoUrl={mark.logoUrl} initials={mark.initials} size={28} />
          <Text style={styles.provider}>{provider}</Text>
        </View>
        {asset ? <Text style={styles.number} numberOfLines={1}>{asset}</Text> : null}
        <Text style={styles.number}>{policy.policy_number}</Text>
        <View style={styles.metaRow}>
          <StatusChip label={info.label} tone={info.tone} />
          <Text style={[styles.date, days !== null && days <= 30 && info.bucket === "active" && styles.dateWarn]}>{dateLabel}</Text>
        </View>
      </View>
      {onPress ? (
        <View style={styles.chevron}>
          <ChevronRight size={18} color={colors.navy900} />
        </View>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.feature,
    padding: space.x3,
    overflow: "hidden",
  },
  pressed: { opacity: 0.9 },
  flex: { flex: 1, gap: 3, minWidth: 0 },
  thumb: { width: 56, height: 56, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  title: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  providerRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  provider: { ...type.meta, color: colors.neutral600, flexShrink: 1 },
  number: { ...type.meta, fontSize: 12, color: colors.neutral600, fontVariant: ["tabular-nums"] },
  metaRow: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", columnGap: space.x2, rowGap: 4, marginTop: 4 },
  date: { ...type.meta, fontSize: 12, lineHeight: 16, color: colors.neutral600, textAlign: "left" },
  dateWarn: { color: colors.gold600 },
  chevron: { width: 36, height: 36, borderRadius: 18, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
});
