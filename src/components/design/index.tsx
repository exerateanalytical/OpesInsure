/**
 * Design system v3 building blocks, matching the reference designs in
 * "app screens/": brand header with the Africa-network art, page title,
 * numbered step indicator, hero card with meta grid, tinted icon tiles,
 * outlined action tiles, detail rows, info banners and a bottom CTA bar.
 * All pure RN + lucide + existing tokens (OTA-safe).
 */
import React, { ReactNode, useState } from "react";
import { PLATFORM_LOGO, WORDMARK } from "@/components/BrandMark";
import { Image, LayoutChangeEvent, Pressable, StyleProp, StyleSheet, Text, TextStyle, View, ViewStyle } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { useKeyboardVisible } from "@/hooks/useKeyboardVisible";
import { router } from "expo-router";
import { ArrowLeft, Bell, Check, ChevronRight, CircleHelp, LucideIcon } from "lucide-react-native";
import { InstitutionMark } from "@/components/InstitutionMark";
import { ripple } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { sessionHome, useSession } from "@/store/session";
import { colors, radius, space, tileIcon, tileIconSize, type } from "@/theme/tokens";


const network = require("../../../assets/brand/header_network.png");
const wave = require("../../../assets/brand/header_wave.png");

// ---------------------------------------------------------------------------
// Brand header
// ---------------------------------------------------------------------------

/**
 * Header back: the previous screen, or, when there is none (opened from a
 * notification, a deep link or after a replace), straight to the signed-in
 * user's home instead of the splash router.
 */
export function goBackOrHome() {
  if (router.canGoBack()) router.back();
  else router.replace((sessionHome(useSession.getState()) ?? "/") as never);
}

/** Centered logo lockup: platform logo (app icon), "OpesInsure" wordmark, gold tagline. */
export function BrandLockup({ size = 40, compact = false }: { size?: number; compact?: boolean }) {
  const { t } = useTranslation();
  return (
    <View style={s.lockup} accessible accessibilityRole="image" accessibilityLabel="OpesInsure">
      <Image source={PLATFORM_LOGO} style={{ width: size, height: size, borderRadius: size * 0.22 }} resizeMode="contain" accessibilityIgnoresInvertColors />
      <View style={s.lockupText}>
        <Text style={[s.wordmark, compact && s.wordmarkCompact]} numberOfLines={1} adjustsFontSizeToFit>
          Opes<Text style={s.wordmarkGold}>Insure</Text>
        </Text>
        <Text style={[s.tagline, compact && s.taglineCompact]} numberOfLines={1} adjustsFontSizeToFit>{t("splashTagline")}</Text>
      </View>
    </View>
  );
}

/**
 * Page header used by every redesigned screen: optional back button, the
 * centered lockup, an optional right action (bell/help), and the gold-navy
 * network swoosh in the top-right corner. Then the page title + subtitle.
 */
export function BrandHeader({
  title,
  subtitle,
  back = true,
  right = "bell",
  onRight,
  badge,
  titleRow,
}: {
  title?: string;
  subtitle?: string;
  back?: boolean;
  right?: "bell" | "help" | null | ReactNode;
  onRight?: () => void;
  /** Red dot / count on the bell. */
  badge?: number | boolean;
  /** Content rendered to the right of the title (e.g. a CTA button). */
  titleRow?: ReactNode;
}) {
  const { t } = useTranslation();
  const rightNode =
    right === "bell" ? (
      <HeaderIconButton icon={Bell} label={t("notifications")} onPress={onRight ?? (() => router.push("/notifications" as never))} badge={badge} />
    ) : right === "help" ? (
      <HeaderIconButton icon={CircleHelp} label={t("helpComplaints")} onPress={onRight ?? (() => router.push("/support/faq" as never))} />
    ) : right ?? <View style={s.iconBtnSpacer} />;
  const [rowW, setRowW] = useState(0);
  const [slotX, setSlotX] = useState(0);
  const [inner, setInner] = useState({ x: 0, w: 0 });
  const lockEnd = inner.w ? slotX + inner.x + inner.w : 0;
  // Gap between the lockup and the 44dp right control (8dp clearance each side).
  const artW = rowW && lockEnd ? Math.min(140, rowW - lockEnd - 44 - 32) : 0;
  return (
    <View style={s.header}>
      <View style={s.topRow} onLayout={(e: LayoutChangeEvent) => setRowW(e.nativeEvent.layout.width)}>
        {/* Decorative art lives only in the free gap between the lockup and
            the right control, so it never sits behind text or buttons. */}
        {artW >= 44 ? (
          <View
            style={[s.artBox, { left: lockEnd + 16, width: artW }]}
            pointerEvents="none"
            accessibilityElementsHidden
            importantForAccessibility="no-hide-descendants"
          >
            <Image source={network} style={[s.art, { width: Math.min(artW, 56), height: Math.min(artW, 56) }]} resizeMode="contain" />
            <Image source={wave} style={[s.wave, { width: artW, height: Math.round(artW * 0.32) }]} resizeMode="contain" />
          </View>
        ) : null}
        {back ? <HeaderIconButton icon={ArrowLeft} label={t("back")} onPress={goBackOrHome} /> : null}
        <View style={[s.lockupSlot, back && s.lockupCentered]} onLayout={(e: LayoutChangeEvent) => setSlotX(e.nativeEvent.layout.x)}>
          <View onLayout={(e: LayoutChangeEvent) => setInner({ x: e.nativeEvent.layout.x, w: e.nativeEvent.layout.width })}>
            <BrandLockup compact={back} size={back ? 32 : 40} />
          </View>
        </View>
        <View style={s.rightSlot}>{rightNode}</View>
      </View>
      {title ? (
        <View style={s.titleBlock}>
          <View style={s.titleRow}>
            <View style={s.flex}>
              <Text accessibilityRole="header" style={s.title} maxFontSizeMultiplier={1.6}>{title}</Text>
              {subtitle ? <Text style={s.subtitle}>{subtitle}</Text> : null}
            </View>
            {titleRow}
          </View>
        </View>
      ) : null}
    </View>
  );
}

export function HeaderIconButton({ icon: Icon, label, onPress, badge }: { icon: LucideIcon; label: string; onPress: () => void; badge?: number | boolean }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} hitSlop={6} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [s.iconBtn, pressed && s.pressed]}>
      <Icon size={22} color={colors.navy900} strokeWidth={2} />
      {badge ? (
        <View style={s.badge}>
          {typeof badge === "number" ? <Text style={s.badgeText}>{badge > 9 ? "9+" : badge}</Text> : null}
        </View>
      ) : null}
    </Pressable>
  );
}

// ---------------------------------------------------------------------------
// Step indicator
// ---------------------------------------------------------------------------

/** "1 Select Policy — 2 Incident — 3 Evidence — 4 Review" with checks for done steps. */
export function StepIndicator({ steps, current, captions, labelStyle }: { steps: string[]; current: number; captions?: (string | null)[]; labelStyle?: StyleProp<TextStyle> }) {
  const { t } = useTranslation();
  return (
    <View style={s.steps} accessibilityRole="progressbar" accessibilityLabel={t("stepOf", { current: current + 1, total: steps.length, label: steps[current] ?? "" })}>
      {steps.map((label, i) => {
        const done = i < current;
        const active = i === current;
        const last = i === steps.length - 1;
        return (
          <View key={label} style={s.stepCol}>
            <View style={s.stepTrackRow}>
              <View style={[s.stepLine, i === 0 && s.stepLineHidden, (done || active) && s.stepLineOn]} />
              <View style={[s.stepDot, done && s.stepDotDone, active && s.stepDotActive]}>
                {done ? <Check size={14} color={colors.white} strokeWidth={3} /> : <Text style={[s.stepNum, active && s.stepNumActive]}>{i + 1}</Text>}
              </View>
              <View style={[s.stepLine, last && s.stepLineHidden, done && s.stepLineOn]} />
            </View>
            <Text style={[s.stepLabel, active && s.stepLabelActive, done && s.stepLabelDone, labelStyle]} numberOfLines={2}>{label}</Text>
            {captions?.[i] ? <Text style={s.stepCaption}>{captions[i]}</Text> : null}
          </View>
        );
      })}
    </View>
  );
}

// ---------------------------------------------------------------------------
// Hero card (policy / product summary) + meta grid
// ---------------------------------------------------------------------------

export type HeroMeta = { icon: LucideIcon; label: string; value: string; tone?: "default" | "danger" | "warning" };

export function HeroCard({
  image,
  icon: Icon,
  title,
  provider,
  providerLogo,
  providerInitials,
  lines = [],
  chip,
  meta = [],
  metaColumns,
  children,
  style,
  compact,
}: {
  image?: number | { uri: string } | null;
  icon?: LucideIcon;
  title: string;
  provider?: string | null;
  providerLogo?: string | null;
  providerInitials?: string | null;
  lines?: (string | null | undefined)[];
  chip?: ReactNode;
  meta?: HeroMeta[];
  /** Meta grid columns (default: 3 when the count divides by 3, else 2). */
  metaColumns?: 2 | 3;
  children?: ReactNode;
  style?: StyleProp<ViewStyle>;
  /** Smaller icon box and a chip that wraps under the title on narrow phones. */
  compact?: boolean;
}) {
  return (
    <View style={[s.card, style]}>
      <View style={s.heroRow}>
        {image ? (
          <Image source={image} style={s.heroImage} resizeMode="cover" />
        ) : Icon ? (
          <View style={[s.heroIconBox, compact && s.heroIconBoxCompact]}>
            <Icon size={tileIconSize(compact ? 60 : 84)} color={colors.navy900} strokeWidth={tileIcon.stroke} />
          </View>
        ) : null}
        <View style={s.flex}>
          <View style={[s.heroTitleRow, compact && s.heroTitleRowWrap]}>
            <Text style={[s.cardTitle, compact ? s.heroTitleGrow : s.flex]}>{title}</Text>
            {chip}
          </View>
          {provider ? (
            <View style={s.providerRow}>
              <InstitutionMark logoUrl={providerLogo} initials={providerInitials ?? provider.slice(0, 2).toUpperCase()} size={22} />
              <Text style={s.providerText}>{provider}</Text>
            </View>
          ) : null}
          {lines.filter(Boolean).map((l, i) => (
            <Text key={i} style={s.heroLine}>{l}</Text>
          ))}
        </View>
      </View>
      {meta.length ? <MetaGrid items={meta} columns={metaColumns} /> : null}
      {children}
    </View>
  );
}

/** Icon + label + value cells separated by hairlines, 2 or 3 per row. */
export function MetaGrid({ items, columns }: { items: HeroMeta[]; columns?: 2 | 3 }) {
  const cols = columns ?? (items.length % 3 === 0 ? 3 : 2);
  return (
    <View style={s.metaGrid}>
      {items.map((m, i) => {
        const Icon = m.icon;
        const tone = m.tone === "danger" ? colors.dangerText : m.tone === "warning" ? colors.gold600 : colors.navy950;
        return (
          <View key={`${m.label}-${i}`} style={[s.metaCell, cols === 3 && s.metaCellStacked, { width: `${100 / cols}%` }, i % cols !== 0 && s.metaCellBorder, i >= cols && s.metaCellTop]}>
            <Icon size={cols === 3 ? 18 : 20} color={m.tone === "danger" ? colors.danger : colors.navy800} />
            <View style={cols === 3 ? undefined : s.flex}>
              <Text style={[s.metaLabel, cols === 3 && s.metaLabelSmall]} numberOfLines={2}>{m.label}</Text>
              <Text style={[s.metaValue, cols === 3 && s.metaValueSmall, { color: tone }]}>{m.value}</Text>
            </View>
          </View>
        );
      })}
    </View>
  );
}

// ---------------------------------------------------------------------------
// Tiles, rows, banners, CTA
// ---------------------------------------------------------------------------

export type Tint = "blue" | "gold" | "red" | "green" | "neutral" | "purple";
const tints: Record<Tint, { bg: string; fg: string }> = {
  blue: { bg: colors.blue50, fg: colors.blue600 },
  gold: { bg: colors.gold50, fg: colors.gold600 },
  red: { bg: colors.dangerSoft, fg: colors.danger },
  green: { bg: colors.successSoft, fg: colors.success },
  neutral: { bg: colors.neutral100, fg: colors.navy800 },
  purple: { bg: colors.purple50, fg: colors.purple700 },
};

/** Soft tinted square with a large icon (design: policy actions row). */
export function IconTile({ icon: Icon, label, tint = "blue", onPress, disabled, style }: { icon: LucideIcon; label: string; tint?: Tint; onPress?: () => void; disabled?: boolean; style?: StyleProp<ViewStyle> }) {
  const c = tints[tint];
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!disabled }}
      disabled={disabled}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [s.tile, { backgroundColor: c.bg }, pressed && s.pressed, disabled && s.disabled, style]}
    >
      <Icon size={tileIconSize(48)} color={c.fg} strokeWidth={tileIcon.stroke} />
      <Text style={[s.tileLabel, { color: tint === "neutral" ? colors.navy950 : c.fg }]} numberOfLines={3}>{label}</Text>
    </Pressable>
  );
}

/** Outlined white tile with icon + label (design: View Policy / Download / Share); `filled` = solid blue600 primary tile (design: claim timeline Add information). */
export function ActionTile({ icon: Icon, label, onPress, loading, disabled, style, filled }: { icon: LucideIcon; label: string; onPress?: () => void; loading?: boolean; disabled?: boolean; style?: StyleProp<ViewStyle>; filled?: boolean }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ disabled: !!disabled, busy: !!loading }} disabled={disabled || loading} onPress={onPress} android_ripple={ripple(filled)} style={({ pressed }) => [s.actionTile, filled && s.actionTileFilled, pressed && s.pressed, disabled && s.disabled, style]}>
      <Icon size={22} color={filled ? colors.white : colors.blue600} />
      <Text style={[s.actionTileLabel, filled && s.actionTileLabelFilled]} numberOfLines={2}>{label}</Text>
    </Pressable>
  );
}

/** Tinted rounded-square icon (radius ~14 at 44–56dp) at the start of a list row or section title; `round` keeps a circle (avatars). */
export function TintedIcon({ icon: Icon, tint = "blue", size = 44, round = false }: { icon: LucideIcon; tint?: Tint; size?: number; round?: boolean }) {
  const c = tints[tint];
  return (
    <View style={{ width: size, height: size, borderRadius: round ? size / 2 : Math.round(size * 0.28), backgroundColor: c.bg, alignItems: "center", justifyContent: "center" }}>
      {/* Glyph fills the tile (tileIconSize); a circle clips its corners, so round avatars stay a touch smaller. */}
      <Icon size={round ? Math.round(size * 0.7) : tileIconSize(size)} color={c.fg} strokeWidth={tileIcon.stroke} />
    </View>
  );
}

/** Label / value row with an optional leading icon (design: Renewal Overview, transaction detail). */
export function DetailRow({ icon: Icon, label, value, valueNode, strong, tint, compact }: { icon?: LucideIcon; label: string; value?: string | null; valueNode?: ReactNode; strong?: boolean; tint?: Tint; /** 14dp text for dense summary cards. */ compact?: boolean }) {
  return (
    <View style={s.detailRow}>
      {Icon ? (tint ? <TintedIcon icon={Icon} tint={tint} size={40} /> : <Icon size={20} color={colors.navy800} />) : null}
      <Text style={[s.detailLabel, Icon && s.detailLabelIcon, compact && s.detailTextCompact]}>{label}</Text>
      <View style={s.detailValueWrap}>
        {valueNode ?? <Text style={[s.detailValue, strong && s.detailValueStrong, compact && s.detailTextCompact]}>{value ?? "—"}</Text>}
      </View>
    </View>
  );
}

/** Row of check bullets (gold checks by default). */
export function CheckList({ items, tint = "gold", columns = 1, compact, plain }: { items: string[]; tint?: Tint; columns?: 1 | 2; /** 14dp text for dense cards. */ compact?: boolean; /** Bare tinted tick without the filled dot. */ plain?: boolean }) {
  const c = tints[tint];
  return (
    <View style={[s.checkList, columns === 2 && s.checkListTwo]}>
      {items.map((it, i) => (
        <View key={i} style={[s.checkItem, columns === 2 && s.checkItemHalf]}>
          {plain ? (
            <View style={s.checkDot}>
              <Check size={16} color={tint === "gold" ? colors.gold500 : c.fg} strokeWidth={3} />
            </View>
          ) : (
            <View style={[s.checkDot, { backgroundColor: c.fg }]}>
              <Check size={11} color={colors.white} strokeWidth={3} />
            </View>
          )}
          <Text style={[s.checkText, compact && s.checkTextCompact]}>{it}</Text>
        </View>
      ))}
    </View>
  );
}

/** Soft banner: info (blue), warning (gold), danger (red), success (green). */
export function Banner({ icon: Icon, tint = "blue", title, body, right, onPress }: { icon: LucideIcon; tint?: Tint; title?: string; body?: string; right?: ReactNode; onPress?: () => void }) {
  const c = tints[tint];
  const inner = (
    <>
      <TintedIcon icon={Icon} tint={tint} size={40} />
      <View style={s.flex}>
        {title ? <Text style={s.bannerTitle}>{title}</Text> : null}
        {body ? <Text style={s.bannerBody}>{body}</Text> : null}
      </View>
      {right ?? (onPress ? <ChevronRight size={20} color={colors.navy900} /> : null)}
    </>
  );
  if (onPress)
    return (
      <Pressable accessibilityRole="button" onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [s.banner, { backgroundColor: c.bg }, pressed && s.pressed]}>
        {inner}
      </Pressable>
    );
  return <View style={[s.banner, { backgroundColor: c.bg }]}>{inner}</View>;
}

/** Section heading with optional trailing link ("View All >"). */
export function SectionHeading({ title, icon: Icon, action, onAction, right }: { title: string; icon?: LucideIcon; action?: string; onAction?: () => void; right?: ReactNode }) {
  return (
    <View style={s.sectionRow}>
      {Icon ? <Icon size={22} color={colors.navy900} /> : null}
      <Text accessibilityRole="header" style={[s.sectionTitle, s.flex]}>{title}</Text>
      {right}
      {action ? (
        <Pressable accessibilityRole="button" onPress={onAction} hitSlop={8} style={s.sectionAction}>
          <Text style={s.sectionActionText}>{action}</Text>
          <ChevronRight size={18} color={colors.blue600} />
        </Pressable>
      ) : null}
    </View>
  );
}

/** Pinned bottom bar with the primary CTA (and optional secondary link), safe-area aware. */
export function CtaBar({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  // FORM-003: while the keyboard is up it covers the gesture area, so the
  // bottom inset would only push the pinned action further up.
  const keyboard = useKeyboardVisible();
  return <View style={[s.ctaBar, { paddingBottom: space.x3 + (keyboard ? 0 : insets.bottom) }]}>{children}</View>;
}

/** Selectable option card with a radio (design: Renewal Options, policy picker). */
export function RadioCard({ selected, onPress, icon: Icon, tint = "blue", title, subtitle, right, trailing, children, style }: { selected: boolean; onPress: () => void; icon?: LucideIcon; tint?: Tint; title: string; subtitle?: string | null; right?: ReactNode; /** Rendered after the radio dot (e.g. a chevron). */ trailing?: ReactNode; children?: ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <Pressable accessibilityRole="radio" accessibilityState={{ selected }} accessibilityLabel={[title, subtitle].filter(Boolean).join(". ")} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [s.radioCard, selected && s.radioCardOn, pressed && s.pressed, style]}>
      <View style={s.radioRow}>
        {Icon ? <TintedIcon icon={Icon} tint={tint} size={48} /> : null}
        <View style={s.flex}>
          <Text style={s.radioTitle}>{title}</Text>
          {subtitle ? <Text style={s.radioSub}>{subtitle}</Text> : null}
          {children}
        </View>
        {right}
        <View style={[s.radio, selected && s.radioOn]}>{selected ? <View style={s.radioInner} /> : null}</View>
        {trailing}
      </View>
    </Pressable>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  // header
  header: { paddingTop: space.x2, gap: space.x4, overflow: "visible" },
  artBox: { position: "absolute", top: 0, bottom: 0, alignItems: "center", justifyContent: "center", overflow: "hidden" },
  art: { opacity: 0.5 },
  wave: { position: "absolute", bottom: 0, opacity: 0.6 },
  topRow: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 52 },
  lockupSlot: { flexShrink: 1 },
  lockupCentered: { flex: 1, alignItems: "center" },
  rightSlot: { marginLeft: "auto" },
  lockup: { flexDirection: "row", alignItems: "center", gap: 6 },
  lockupText: { flexShrink: 1 },
  wordmark: { fontFamily: "Inter_700Bold", fontSize: 24, lineHeight: 28, color: WORDMARK.ink, letterSpacing: -0.4 },
  wordmarkGold: { color: WORDMARK.accent },
  wordmarkCompact: { fontSize: 20, lineHeight: 24 },
  taglineCompact: { fontSize: 6, lineHeight: 8, letterSpacing: 0.3 },
  tagline: { fontFamily: "Inter_700Bold", fontSize: 7.5, lineHeight: 10, letterSpacing: 0.6, color: colors.navy900 },
  iconBtn: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center", overflow: "hidden" },
  iconBtnSpacer: { width: 44, height: 44 },
  badge: { position: "absolute", top: 6, right: 6, minWidth: 10, height: 10, borderRadius: 5, backgroundColor: colors.danger, alignItems: "center", justifyContent: "center", paddingHorizontal: 2 },
  badgeText: { fontFamily: "Inter_700Bold", fontSize: 8, color: colors.white },
  titleBlock: { gap: 2 },
  titleRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  title: { fontFamily: "Inter_700Bold", fontSize: 30, lineHeight: 36, color: colors.navy950, letterSpacing: -0.5 },
  subtitle: { ...type.body, color: colors.neutral600, marginTop: 4 },
  // steps
  steps: { flexDirection: "row", alignItems: "flex-start" },
  stepCol: { flex: 1, alignItems: "center", gap: 6 },
  stepTrackRow: { flexDirection: "row", alignItems: "center", alignSelf: "stretch" },
  stepLine: { flex: 1, height: 2, backgroundColor: colors.neutral200 },
  stepLineOn: { backgroundColor: colors.blue600 },
  stepLineHidden: { backgroundColor: "transparent" },
  stepDot: { width: 34, height: 34, borderRadius: 17, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  stepDotDone: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  stepDotActive: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  stepNum: { ...type.label, color: colors.neutral500 },
  stepNumActive: { color: colors.white },
  stepLabel: { ...type.caption, color: colors.neutral500, textAlign: "center", paddingHorizontal: 2 },
  stepLabelActive: { color: colors.blue600 },
  stepLabelDone: { color: colors.navy950 },
  stepCaption: { ...type.caption, fontFamily: "Inter_400Regular", color: colors.neutral500 },
  // card
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  heroRow: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  heroImage: { width: 104, height: 84, borderRadius: radius.card, backgroundColor: colors.neutral100 },
  heroIconBox: { width: 84, height: 84, borderRadius: radius.card, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  heroIconBoxCompact: { width: 60, height: 60 },
  heroTitleRowWrap: { flexWrap: "wrap", rowGap: 4 },
  heroTitleGrow: { flexGrow: 1, flexShrink: 1, flexBasis: 120 },
  heroTitleRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  providerRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 4 },
  providerText: { ...type.body, color: colors.neutral600, flexShrink: 1 },
  heroLine: { ...type.body, color: colors.neutral600, marginTop: 2 },
  metaGrid: { flexDirection: "row", flexWrap: "wrap", borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  metaCell: { flexDirection: "row", gap: space.x2, alignItems: "flex-start", paddingHorizontal: space.x2, paddingVertical: space.x1 },
  metaCellBorder: { borderLeftWidth: 1, borderLeftColor: colors.neutral200 },
  metaCellTop: { borderTopWidth: 1, borderTopColor: colors.neutral200, marginTop: space.x2, paddingTop: space.x3 },
  metaCellStacked: { flexDirection: "column", gap: 4 },
  metaLabel: { ...type.meta, color: colors.neutral600 },
  metaLabelSmall: { fontSize: 12, lineHeight: 16 },
  metaValueSmall: { fontSize: 14, lineHeight: 19 },
  metaValue: { ...type.label, fontSize: 15, lineHeight: 20, color: colors.navy950 },
  // tiles
  tile: { flex: 1, minHeight: 104, borderRadius: radius.feature, alignItems: "center", justifyContent: "center", gap: space.x2, paddingVertical: space.x3, paddingHorizontal: 2, overflow: "hidden" },
  tileLabel: { ...type.label, textAlign: "center", fontSize: 12.5, lineHeight: 16 },
  actionTile: { flex: 1, minHeight: 64, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, paddingHorizontal: space.x2, overflow: "hidden" },
  actionTileFilled: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  actionTileLabelFilled: { color: colors.white },
  actionTileLabel: { ...type.label, color: colors.navy950, flexShrink: 1, textAlign: "center", fontSize: 13, lineHeight: 17 },
  // detail row
  detailRow: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 36 },
  detailLabel: { ...type.body, color: colors.neutral600, flexShrink: 1, maxWidth: "50%" },
  detailLabelIcon: { maxWidth: "45%" },
  detailValueWrap: { flex: 1, alignItems: "flex-end" },
  detailValue: { ...type.body, color: colors.navy950, textAlign: "right" },
  detailTextCompact: { fontSize: 14, lineHeight: 20 },
  detailValueStrong: { fontFamily: "Inter_700Bold" },
  // checklist
  checkList: { gap: space.x2 },
  checkListTwo: { flexDirection: "row", flexWrap: "wrap", gap: 0 },
  checkItem: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  checkItemHalf: { width: "50%", paddingRight: space.x2, marginBottom: space.x2 },
  checkDot: { width: 20, height: 20, borderRadius: 10, alignItems: "center", justifyContent: "center", marginTop: 1 },
  checkTextCompact: { fontSize: 14, lineHeight: 20 },
  checkText: { ...type.body, color: colors.neutral700, flex: 1 },
  // banner
  banner: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x3, borderRadius: radius.card, overflow: "hidden" },
  bannerTitle: { ...type.label, color: colors.navy950 },
  bannerBody: { ...type.meta, color: colors.neutral700, marginTop: 2 },
  // section
  sectionRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  sectionTitle: { ...type.sectionTitle, fontSize: 20, lineHeight: 26, color: colors.navy950 },
  sectionAction: { flexDirection: "row", alignItems: "center", gap: 2, minHeight: 32 },
  sectionActionText: { ...type.label, color: colors.blue600 },
  // cta
  ctaBar: { paddingHorizontal: space.x5, paddingTop: space.x3, gap: space.x2, backgroundColor: colors.neutral50, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200 },
  // radio card
  radioCard: { backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, overflow: "hidden" },
  radioCardOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  radioRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  radioTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  radioSub: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  radio: { width: 24, height: 24, borderRadius: 12, borderWidth: 2, borderColor: colors.neutral300, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  radioOn: { borderColor: colors.blue600 },
  radioInner: { width: 12, height: 12, borderRadius: 6, backgroundColor: colors.blue600 },
});
