/**
 * Shared pieces of the renewal journey (Renew → Quote → Compare → Review):
 * the four-step indicator, the policy hero card, price rows and the
 * mobile-money provider tiles. Built on the v3 design blocks; every value
 * shown comes from the policy record or the renewal quote payload.
 */
import React, { ReactNode } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { Car, LucideIcon, ShieldCheck } from "lucide-react-native";
import { HeroCard, HeroMeta, StepIndicator } from "@/components/design";
import { ripple, StatusChip } from "@/components/ui";
import { Policy, QuoteOffer } from "@/api/client";
import { localized, providerName } from "@/lib/purchase";
import { carrierLogo, insuredObjectLabel, RenewalPolicy } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export type RenewalStep = 0 | 1 | 2 | 3;

/** "1 Quote — 2 Compare — 3 Review — 4 Payment". */
export function RenewalSteps({ current }: { current: RenewalStep }) {
  const { t } = useTranslation();
  return <StepIndicator steps={[t("rnStepQuote"), t("rnStepCompare"), t("rnStepReview"), t("rnStepPayment")]} current={current} />;
}

/** Product title / provider / cover / vehicle for the hero, from the policy record + (optionally) the same-carrier offer. */
export function useRenewalIdentity(policy: RenewalPolicy | Policy | null | undefined, offer?: QuoteOffer | null, riskFacts?: Record<string, unknown> | null) {
  const f = useFormatters();
  const { t } = useTranslation();
  const p = policy as RenewalPolicy | null | undefined;
  const productName = localized(offer?.product?.name, f.language) || p?.product_name || null;
  // The offer being bought names the insurer; the policy's carrier is only the fallback (a compared offer may come from another insurer).
  const provider = offer?.carrier?.party?.display_name || p?.carrier_name || p?.carrier?.party?.display_name || (offer ? providerName(offer, f.language) : null);
  const lineCode = String(offer?.product?.line_code ?? "").toUpperCase();
  const isMotor = lineCode === "MOTOR" || /motor|auto|véhicule|vehicle/i.test(productName ?? "");
  const vehicle = insuredObjectLabel(p, riskFacts);
  const title = productName ?? t("insurancePolicy");
  return { productName, provider, vehicle, title, isMotor, icon: (isMotor ? Car : ShieldCheck) as LucideIcon, logo: offer ? carrierLogo(offer) : null };
}

export function RenewalHero({
  policy,
  offer,
  riskFacts,
  chip,
  meta,
  title,
  lines,
  children,
}: {
  policy: RenewalPolicy | Policy | null | undefined;
  offer?: QuoteOffer | null;
  riskFacts?: Record<string, unknown> | null;
  chip?: ReactNode;
  meta?: HeroMeta[];
  title?: string;
  lines?: (string | null | undefined)[];
  children?: ReactNode;
}) {
  const id = useRenewalIdentity(policy, offer, riskFacts);
  return (
    <HeroCard icon={id.icon} title={title ?? id.title} provider={id.provider} providerLogo={id.logo} lines={lines ?? [id.productName && id.productName !== id.title ? id.productName : null, id.vehicle]} chip={chip} meta={meta} compact>
      {children}
    </HeroCard>
  );
}

/** Gold "Due for Renewal" pill. */
export function DueChip() {
  const { t } = useTranslation();
  return <StatusChip label={t("rnDueChip")} tone="warning" />;
}

/** Label / amount row for price breakdowns; `tone` colours the amount. */
export function PriceRow({ label, value, strong, tone, icon: Icon, sub }: { label: string; value: string; strong?: boolean; tone?: "default" | "success"; icon?: LucideIcon; sub?: string }) {
  const color = tone === "success" ? colors.successText : colors.navy950;
  return (
    <View style={s.priceRow}>
      {Icon ? <Icon size={20} color={colors.navy800} /> : null}
      <Text style={[s.priceLabel, tone === "success" && { color }]}>{label}</Text>
      <View style={s.priceValueWrap}>
        <Text style={[s.priceValue, strong && s.priceValueStrong, { color }]}>{value}</Text>
        {sub ? <Text style={s.priceSub}>{sub}</Text> : null}
      </View>
    </View>
  );
}

/** Highlighted total band (design: "Total Renewal Premium 88,250 XAF"). */
export function TotalBand({ label, value }: { label: string; value: string }) {
  return (
    <View style={s.totalBand}>
      <Text style={s.totalLabel}>{label}</Text>
      <Text style={s.totalValue}>{value}</Text>
    </View>
  );
}

/** Soft blue box with a heading (design: "What Stays the Same?"). */
export function InfoBox({ title, children, tint = "blue" }: { title?: string; children: ReactNode; tint?: "blue" | "green" | "neutral" }) {
  const bg = tint === "green" ? colors.successSoft : tint === "neutral" ? colors.neutral50 : colors.blue50;
  return (
    <View style={[s.infoBox, { backgroundColor: bg }]}>
      {title ? <Text style={s.infoTitle}>{title}</Text> : null}
      {children}
    </View>
  );
}

export type Network = "mtn_momo" | "orange_money";

/** MTN MoMo / Orange Money tiles (design: Payment Method). `readOnly` renders the current choice without interaction. */
export function NetworkTiles({ value, onChange, disabled, readOnly }: { value: Network | string | null | undefined; onChange?: (v: Network) => void; disabled?: boolean; readOnly?: boolean }) {
  const { t } = useTranslation();
  const items: { key: Network; label: string; sub: string; badge: string; bg: string; fg: string }[] = [
    { key: "mtn_momo", label: t("rrMtn"), sub: t("rrMtnSub"), badge: "MTN", bg: "#FFCC00", fg: colors.navy950 },
    { key: "orange_money", label: t("rrOrange"), sub: t("rrOrangeSub"), badge: "orange", bg: "#FF7900", fg: colors.white },
  ];
  return (
    <View style={s.networks}>
      {items.map((it) => {
        const selected = value === it.key;
        const inner = (
          <>
            <View style={[s.networkBadge, { backgroundColor: it.bg }]}>
              <Text style={[s.networkBadgeText, { color: it.fg }]}>{it.badge}</Text>
            </View>
            <View style={s.flex}>
              <Text style={s.networkLabel}>{it.label}</Text>
              <Text style={s.networkSub}>{it.sub}</Text>
            </View>
            <View style={[s.radio, selected && s.radioOn]}>{selected ? <View style={s.radioInner} /> : null}</View>
          </>
        );
        if (readOnly)
          return (
            <View key={it.key} accessibilityRole="radio" accessibilityState={{ selected }} style={[s.network, selected && s.networkOn]}>
              {inner}
            </View>
          );
        return (
          <Pressable
            key={it.key}
            accessibilityRole="radio"
            accessibilityState={{ selected, disabled: !!disabled }}
            accessibilityLabel={it.label}
            disabled={disabled}
            onPress={() => onChange?.(it.key)}
            android_ripple={ripple()}
            style={({ pressed }) => [s.network, selected && s.networkOn, pressed && s.pressed]}
          >
            {inner}
          </Pressable>
        );
      })}
    </View>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  priceRow: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 36 },
  priceLabel: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral600, flexGrow: 1, flexShrink: 1, flexBasis: 80, minWidth: 104 },
  priceValueWrap: { alignItems: "flex-end", flexShrink: 1 },
  priceValue: { ...type.body, fontSize: 14, lineHeight: 20, textAlign: "right", color: colors.navy950, fontVariant: ["tabular-nums"] },
  priceValueStrong: { fontFamily: "Inter_700Bold" },
  priceSub: { ...type.meta, color: colors.neutral500 },
  totalBand: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x3, backgroundColor: colors.blue50, borderRadius: radius.card, paddingHorizontal: space.x3, paddingVertical: space.x3 },
  totalLabel: { ...type.cardTitle, fontSize: 15, lineHeight: 20, color: colors.navy950, flexShrink: 1 },
  totalValue: { ...type.sectionTitle, fontSize: 19, lineHeight: 24, flexShrink: 0, color: colors.navy950, fontVariant: ["tabular-nums"] },
  infoBox: { borderRadius: radius.card, padding: space.x3, gap: space.x2 },
  infoTitle: { ...type.cardTitle, fontSize: 16, lineHeight: 22, color: colors.navy950 },
  networks: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  network: { flexGrow: 1, flexBasis: 220, minHeight: 64, borderWidth: 1.5, borderColor: colors.neutral200, borderRadius: radius.card, backgroundColor: colors.white, flexDirection: "row", alignItems: "center", gap: space.x2, padding: space.x2, overflow: "hidden" },
  networkOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  networkBadge: { width: 40, height: 28, borderRadius: 8, alignItems: "center", justifyContent: "center" },
  networkBadgeText: { fontFamily: "Inter_700Bold", fontSize: 11 },
  networkLabel: { ...type.label, fontSize: 13, lineHeight: 17, color: colors.navy950 },
  networkSub: { ...type.caption, fontFamily: "Inter_400Regular", color: colors.neutral600 },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: colors.neutral300, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  radioOn: { borderColor: colors.blue600 },
  radioInner: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600 },
});
