/**
 * Presentational sections of the customer Home (app/(customer)/(tabs)/index.tsx),
 * owner-approved layout 2026-09-29: one-line greeting, Compare card, "Your
 * policies", merged "In progress" card, first-quote card, Quick actions.
 * Data and section states come from the screen (src/lib/homeFeed.ts).
 */
import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ArrowRight, FileText, Handshake, LifeBuoy, LucideIcon, ReceiptText, RefreshCw, ShieldAlert, ShieldCheck, Wallet } from "lucide-react-native";
import type { Claim, WalletPolicy } from "@/api/client";
import type { CustomerQuote } from "@/api/customer";
import { ripple, StatusChip } from "@/components/ui";
import { SectionHeading, TintedIcon, type Tint } from "@/components/design";
import { PolicyListCard } from "@/components/policies/PolicyListCard";
import { HeritagePattern } from "@/components/HeritagePattern";
import { useTranslation } from "@/i18n";
import { claimStatusKey, claimTone } from "@/lib/claimStatus";
import { quoteStateKey, quoteTone } from "@/lib/quoteWorkflow";
import type { InProgressItem, SectionState } from "@/lib/homeFeed";
import { colors, radius, space, tileIcon, tileIconSize, type } from "@/theme/tokens";

export type HomeItem = InProgressItem<CustomerQuote, Claim, WalletPolicy>;

/** i18n key for the time-of-day greeting. */
export const greetingKey = (h = new Date().getHours()): "greetingMorning" | "greetingAfternoon" | "greetingEvening" =>
  h < 12 ? "greetingMorning" : h < 18 ? "greetingAfternoon" : "greetingEvening";

/** "Good morning, Jude" on one line; the generic welcome when there is no name. */
export function HomeGreeting({ name, hour }: { name?: string | null; hour?: number }) {
  const { t } = useTranslation();
  return (
    <Text accessibilityRole="header" style={st.greeting} numberOfLines={1}>
      {name ? t("homeGreetingTimeName", { part: t(greetingKey(hour)), name }) : t("homeGreeting")}
    </Text>
  );
}

/** Navy promo card: whole card opens the quote wizard. */
export function CompareCard() {
  const { t } = useTranslation();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${t("homeCtaTitle")}. ${t("homeCtaBody")}`}
      onPress={() => router.push("/quote/product")}
      android_ripple={ripple(true)}
      style={({ pressed }) => [st.cta, pressed && st.pressed]}
    >
      <HeritagePattern variant="ndop" opacity={0.08} />
      <View style={st.ctaTop}>
        <View style={st.ctaThumb}>
          <ShieldCheck size={tileIconSize(56)} color={colors.gold500} strokeWidth={tileIcon.stroke} />
        </View>
        <View style={st.ctaCopy}>
          <Text style={st.ctaTitle}>{t("homeCtaTitle")}</Text>
          <Text style={st.ctaBody}>{t("homeCtaBody")}</Text>
        </View>
      </View>
      {/* Bottom-right action; the whole card stays the touch target. */}
      <View style={st.ctaButton}>
        <Text style={st.ctaButtonText} numberOfLines={1}>{t("propGetQuote")}</Text>
        <ArrowRight size={16} color={colors.navy950} strokeWidth={2.4} />
      </View>
    </Pressable>
  );
}

/** Grey rounded placeholders while a section loads for the first time. */
export function SkeletonRows({ rows, variant }: { rows: number; variant: "policy" | "row" }) {
  const { t } = useTranslation();
  if (variant === "policy")
    return (
      <View style={st.group} accessibilityRole="progressbar" accessibilityLabel={t("loading")}>
        {Array.from({ length: rows }, (_, i) => (
          <View key={i} style={[st.card, st.skelPolicy]}>
            <View style={[st.bone, st.skelThumb]} />
            <View style={st.skelLines}>
              <View style={[st.bone, st.skelLineWide]} />
              <View style={[st.bone, st.skelLine]} />
            </View>
          </View>
        ))}
      </View>
    );
  return (
    <View style={st.card} accessibilityRole="progressbar" accessibilityLabel={t("loading")}>
      {Array.from({ length: rows }, (_, i) => (
        <View key={i} style={[st.row, i > 0 && st.rowDivider]}>
          <View style={[st.bone, st.skelTile]} />
          <View style={st.skelLines}>
            <View style={[st.bone, st.skelLineWide]} />
            <View style={[st.bone, st.skelLine]} />
          </View>
          <View style={[st.bone, st.skelChip]} />
        </View>
      ))}
    </View>
  );
}

/** One compact "Couldn't load — Retry" row. */
export function SectionRetry({ onRetry, divider }: { onRetry: () => void; divider?: boolean }) {
  const { t } = useTranslation();
  return (
    <View style={[st.retryRow, divider && st.rowDivider]}>
      <Text style={st.retryText} accessibilityRole="alert">{t("homeLoadFailed")}</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} hitSlop={8} style={st.retryBtn}>
        <Text style={st.retryLink}>{t("retry")}</Text>
      </Pressable>
    </View>
  );
}

/** "Your policies": up to two policy cards, See all → Policies tab. */
export function HomePolicies({ state, policies, onRetry }: { state: SectionState; policies: WalletPolicy[]; onRetry: () => void }) {
  const { t } = useTranslation();
  if (state === "hidden") return null;
  return (
    <View style={st.section}>
      <SectionHeading title={t("homeYourPolicies")} action={t("seeAll")} onAction={() => router.push("/(customer)/(tabs)/policies")} />
      {state === "skeleton" ? (
        <SkeletonRows rows={2} variant="policy" />
      ) : state === "error" ? (
        <View style={st.card}>
          <SectionRetry onRetry={onRetry} />
        </View>
      ) : (
        <View style={st.group}>
          {policies.map((p) => (
            <PolicyListCard key={p.id} policy={p} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: p.id } })} />
          ))}
        </View>
      )}
    </View>
  );
}

/** "In progress": one card with quotes, claims and renewals due, hairline-separated. */
export function InProgressSection({
  state,
  items,
  seeAll,
  error,
  onRetry,
}: {
  state: SectionState;
  items: HomeItem[];
  seeAll: string;
  /** Some source failed while others loaded: a retry row closes the card. */
  error?: boolean;
  onRetry: () => void;
}) {
  const { t } = useTranslation();
  if (state === "hidden") return null;
  return (
    <View style={st.section}>
      <SectionHeading title={t("homeInProgress")} action={t("seeAll")} onAction={() => router.push(seeAll as never)} />
      {state === "skeleton" ? (
        <SkeletonRows rows={3} variant="row" />
      ) : (
        <View style={st.card}>
          {state === "list" ? items.map((item, i) => <InProgressRow key={item.key} item={item} divider={i > 0} />) : null}
          {state === "error" || error ? <SectionRetry onRetry={onRetry} divider={state === "list"} /> : null}
        </View>
      )}
    </View>
  );
}

function InProgressRow({ item, divider }: { item: HomeItem; divider: boolean }) {
  const { t, td, date } = useTranslation();
  let icon: LucideIcon;
  let tint: Tint;
  let title: string;
  let meta: string;
  let chip: { label: string; tone: "neutral" | "success" | "warning" | "info" | "danger" };
  let go: () => void;
  if (item.kind === "quote") {
    const q = item.quote;
    const product = q.product_name ?? q.vehicle_label;
    icon = ReceiptText;
    tint = "blue";
    title = product ? t("homeQuoteTitle", { product }) : t("quote");
    meta = q.offer_count ? t("offersCount", { count: q.offer_count }) : q.created_at ? date(q.created_at) : "";
    chip = { label: td(quoteStateKey(q), td(`quoteStatus_${q.status}`, q.status)), tone: quoteTone(q) };
    go = () => router.push({ pathname: "/quotes/[id]", params: { id: q.id } });
  } else if (item.kind === "claim") {
    const c = item.claim;
    const reported = c.created_at || c.incident_at;
    icon = ShieldAlert;
    tint = "gold";
    title = c.claim_number;
    meta = reported ? t("homeClaimReported", { date: date(reported) }) : "";
    chip = { label: td(claimStatusKey(c.status), c.status), tone: claimTone(c.status) };
    go = () => router.push({ pathname: "/claim/[id]", params: { id: c.id } });
  } else {
    const p = item.policy;
    icon = RefreshCw;
    tint = "gold";
    title = t("homeRenewalTitle", { number: p.policy_number });
    meta = item.days <= 0 ? t("homeRenewalDueToday") : item.days === 1 ? t("homeRenewalDueTomorrow") : t("homeRenewalDueIn", { days: item.days });
    chip = { label: t("renew"), tone: "warning" };
    go = () => router.push({ pathname: "/policy/[id]/renew", params: { id: p.id } });
  }
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[title, meta, chip.label].filter(Boolean).join(". ")}
      onPress={go}
      android_ripple={ripple()}
      style={({ pressed }) => [st.row, divider && st.rowDivider, pressed && st.pressed]}
    >
      <TintedIcon icon={icon} tint={tint} size={32} />
      <View style={st.rowCopy}>
        {/* HOME-001: identifiers wrap instead of being cut. */}
        <Text style={st.rowTitle} numberOfLines={2}>{title}</Text>
        {meta ? <Text style={st.rowMeta} numberOfLines={1}>{meta}</Text> : null}
      </View>
      <StatusChip label={chip.label} tone={chip.tone} />
    </Pressable>
  );
}

/** New customer (no active policy, nothing in progress): one card instead of two empty sections. */
export function FirstQuoteCard() {
  const { t } = useTranslation();
  return (
    <View style={[st.card, st.firstQuote]}>
      <View style={st.firstQuoteTop}>
        <TintedIcon icon={FileText} tint="blue" size={40} />
        <View style={st.rowCopy}>
          <Text accessibilityRole="header" style={st.firstQuoteTitle}>{t("homeFirstQuoteTitle")}</Text>
          <Text style={st.firstQuoteBody}>{t("homeFirstQuoteBody")}</Text>
        </View>
      </View>
      <Pressable
        accessibilityRole="button"
        onPress={() => router.push("/quote/product")}
        android_ripple={ripple(true)}
        style={({ pressed }) => [st.firstQuoteBtn, pressed && st.pressed]}
      >
        <Text style={st.firstQuoteBtnText} numberOfLines={1}>{t("propGetQuote")}</Text>
        <ArrowRight size={16} color={colors.white} strokeWidth={2.4} />
      </Pressable>
    </View>
  );
}

const QUICK: { icon: LucideIcon; label: "pdFileClaim" | "homePayments" | "homeFindBroker" | "homeSupport"; href: string }[] = [
  { icon: FileText, label: "pdFileClaim", href: "/claim/new" },
  { icon: Wallet, label: "homePayments", href: "/payments" },
  { icon: Handshake, label: "homeFindBroker", href: "/institutions/brokers" },
  { icon: LifeBuoy, label: "homeSupport", href: "/support" },
];

/** Four equal white tiles: 40dp tinted blue icon tile + two-line label. */
export function QuickActions() {
  const { t } = useTranslation();
  return (
    <View style={st.section}>
      <SectionHeading title={t("homeQuickActions")} />
      <View style={st.quickRow}>
        {QUICK.map((a) => (
          <Pressable
            key={a.href}
            accessibilityRole="button"
            accessibilityLabel={t(a.label)}
            onPress={() => router.push(a.href as never)}
            android_ripple={ripple()}
            style={({ pressed }) => [st.quickTile, pressed && st.pressed]}
          >
            <TintedIcon icon={a.icon} tint="blue" size={40} />
            <Text style={st.quickLabel} numberOfLines={2}>{t(a.label)}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  pressed: { opacity: 0.82 },
  greeting: { fontFamily: "Inter_700Bold", fontSize: 20, lineHeight: 26, color: colors.navy950 },
  section: { gap: space.x3 },
  group: { gap: space.x3 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, overflow: "hidden" },
  // Compare card: same metrics as PolicyListCard (12dp padding, 56dp tile).
  cta: { gap: space.x3, borderRadius: radius.feature, backgroundColor: colors.navyPromo, borderWidth: 1, borderColor: colors.navyPromo, overflow: "hidden", padding: space.x3 },
  ctaTop: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  ctaThumb: { width: 56, height: 56, borderRadius: radius.card, backgroundColor: colors.onNavyTint, alignItems: "center", justifyContent: "center" },
  ctaCopy: { flex: 1, gap: 3, minWidth: 0 },
  ctaTitle: { ...type.cardTitle, color: colors.white },
  ctaBody: { ...type.meta, color: colors.blue100 },
  ctaButton: { alignSelf: "flex-end", flexDirection: "row", alignItems: "center", gap: 6, minHeight: 44, backgroundColor: colors.gold500, borderRadius: radius.pill, paddingHorizontal: space.x4 },
  ctaButtonText: { ...type.label, fontSize: 15, lineHeight: 20, fontFamily: "Inter_700Bold", color: colors.navy950 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 60, paddingHorizontal: space.x3, paddingVertical: 10 },
  rowDivider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200 },
  rowCopy: { flex: 1, minWidth: 0, gap: 2 },
  rowTitle: { ...type.label, color: colors.navy950 },
  rowMeta: { ...type.meta, color: colors.neutral600 },
  retryRow: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 48, paddingHorizontal: space.x3 },
  retryText: { ...type.meta, color: colors.neutral600, flex: 1 },
  retryBtn: { minHeight: 44, justifyContent: "center" },
  retryLink: { ...type.label, color: colors.blue600 },
  bone: { backgroundColor: colors.neutral100, borderRadius: radius.control / 2 },
  skelPolicy: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x3, minHeight: 84 },
  skelThumb: { width: 56, height: 56, borderRadius: radius.card },
  skelTile: { width: 32, height: 32, borderRadius: 9 },
  skelLines: { flex: 1, gap: space.x2 },
  skelLineWide: { height: 12, width: "70%" },
  skelLine: { height: 10, width: "45%" },
  skelChip: { width: 56, height: 22, borderRadius: radius.pill },
  firstQuote: { padding: space.x4, gap: space.x4 },
  firstQuoteTop: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  firstQuoteTitle: { ...type.cardTitle, color: colors.navy950 },
  firstQuoteBody: { ...type.meta, color: colors.neutral600 },
  firstQuoteBtn: { alignSelf: "flex-end", flexDirection: "row", alignItems: "center", gap: 6, minHeight: 44, backgroundColor: colors.blue600, borderRadius: radius.pill, paddingHorizontal: space.x4 },
  firstQuoteBtnText: { ...type.label, fontSize: 15, lineHeight: 20, fontFamily: "Inter_700Bold", color: colors.white },
  quickRow: { flexDirection: "row", gap: space.x2 },
  quickTile: {
    flex: 1,
    minWidth: 0,
    alignItems: "center",
    gap: space.x2,
    paddingVertical: space.x3,
    paddingHorizontal: space.x1,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
  },
  quickLabel: { ...type.caption, color: colors.navy950, textAlign: "center" },
});
