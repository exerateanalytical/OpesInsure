import React, { ReactNode, useEffect, useRef } from "react";
import {
  ActivityIndicator,
  Animated,
  Image,
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  View,
  ViewStyle,
} from "react-native";
import { ChevronRight, LucideIcon } from "lucide-react-native";
import { agentColors as c, agentIcon, agentLayout as L, agentStatus, agentType as T } from "@/theme/agent";
import { useTranslation } from "@/i18n";

/* ------------------------------------------------------------------ */
/* AgentSection — uppercase caption label above a group of rows/cards */
/* ------------------------------------------------------------------ */
export function AgentSection({
  title,
  children,
  action,
  style,
}: {
  title: string;
  children?: ReactNode;
  /** Optional right-aligned element (e.g. a "See all" link). */
  action?: ReactNode;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View style={[s.section, style]}>
      <View style={s.sectionHead}>
        <Text accessibilityRole="header" style={s.sectionLabel}>
          {title.toUpperCase()}
        </Text>
        {action}
      </View>
      {children}
    </View>
  );
}

/* ------------------------------------------------------------------ */
/* AgentCard — white, 1px border, radius 18, no shadow                 */
/* ------------------------------------------------------------------ */
export function AgentCard({
  children,
  style,
  padded = true,
  tone = "default",
  onPress,
  accessibilityLabel,
}: {
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
  /** false for cards that hold AgentNavRows (rows carry their own padding). */
  padded?: boolean;
  /** "danger" = danger-zone card (#FFF3F2 bg, #F97066 border). */
  tone?: "default" | "danger";
  onPress?: () => void;
  accessibilityLabel?: string;
}) {
  const base = [s.card, padded && s.cardPad, tone === "danger" && s.cardDanger, style];
  if (!onPress) return <View style={base}>{children}</View>;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      onPress={onPress}
      style={({ pressed }) => [...base, pressed && s.pressed]}
    >
      {children}
    </Pressable>
  );
}

/* ------------------------------------------------------------------ */
/* AgentStatusChip — locked vocabulary, EN/FR                          */
/* ------------------------------------------------------------------ */
export type AgentChipTone = "success" | "warning" | "danger" | "info" | "neutral";
type Vocab = (typeof agentStatus)[keyof typeof agentStatus][number];
export type AgentStatusKey = Vocab;

const TONE_OF: Record<string, AgentChipTone> = {
  Active: "success",
  Verified: "success",
  Paid: "success",
  Available: "success",
  Recognized: "success",
  Current: "info",
  Accrued: "info",
  Requested: "info",
  Processing: "info",
  Pending: "warning",
  "Pending Verification": "warning",
  "Action Required": "warning",
  "Under Review": "warning",
  "New Device": "warning",
  Disputed: "warning",
  Suspended: "danger",
  Rejected: "danger",
  Failed: "danger",
  Reversed: "danger",
  Suspicious: "danger",
  Inactive: "neutral",
  Expired: "neutral",
  "Signed Out": "neutral",
  Cancelled: "neutral",
};

const TONE_COLORS: Record<AgentChipTone, { fg: string; bg: string }> = {
  success: { fg: c.success, bg: c.successBg },
  warning: { fg: c.warning, bg: c.warningBg },
  danger: { fg: c.danger, bg: c.dangerBg },
  info: { fg: c.info, bg: c.infoBg },
  neutral: { fg: c.secondary, bg: c.surfaceSoft },
};

/** i18n key for a vocabulary word: "Pending Verification" -> agentSt_PendingVerification. */
export const agentStatusKey = (status: string) => `agentSt_${status.replace(/\s+/g, "")}`;

export function AgentStatusChip({
  status,
  tone,
  label,
  icon: Icon,
}: {
  /** A word from the locked vocabulary (src/theme/agent.ts agentStatus). */
  status: AgentStatusKey;
  /** Override the default tone for the word. */
  tone?: AgentChipTone;
  /** Override the translated label (rare; keep the vocabulary). */
  label?: string;
  icon?: LucideIcon;
}) {
  const { td } = useTranslation();
  const t = tone ?? TONE_OF[status] ?? "neutral";
  const col = TONE_COLORS[t];
  const text = label ?? td(agentStatusKey(status), status);
  return (
    <View style={[s.chip, { backgroundColor: col.bg }]} accessibilityLabel={text}>
      {Icon ? <Icon size={14} color={col.fg} strokeWidth={2} /> : <View style={[s.chipDot, { backgroundColor: col.fg }]} />}
      <Text style={[s.chipText, { color: col.fg }]} numberOfLines={1}>
        {text}
      </Text>
    </View>
  );
}

/* ------------------------------------------------------------------ */
/* AgentNavRow                                                         */
/* ------------------------------------------------------------------ */
export function AgentNavRow({
  icon: Icon,
  title,
  subtitle,
  status,
  statusTone,
  right,
  onPress,
  danger = false,
  divider = true,
  busy = false,
  chevron = true,
  accessibilityLabel,
}: {
  icon: LucideIcon;
  title: string;
  subtitle?: string | null;
  /** Optional chip from the locked vocabulary. */
  status?: AgentStatusKey;
  statusTone?: AgentChipTone;
  /** Optional custom right element (shown before the chevron). */
  right?: ReactNode;
  onPress?: () => void;
  danger?: boolean;
  /** Top hairline; pass false on the first row of a card. */
  divider?: boolean;
  busy?: boolean;
  chevron?: boolean;
  accessibilityLabel?: string;
}) {
  const tint = danger ? c.danger : agentIcon.color;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? (subtitle ? `${title}, ${subtitle}` : title)}
      accessibilityState={{ busy, disabled: busy || !onPress }}
      disabled={busy || !onPress}
      onPress={onPress}
      style={({ pressed }) => [s.row, divider && s.rowDivider, pressed && s.rowPressed]}
    >
      <Icon size={agentIcon.row} color={tint} strokeWidth={agentIcon.stroke} />
      <View style={s.rowText}>
        <Text style={[s.rowTitle, danger && { color: c.danger }]}>{title}</Text>
        {subtitle ? <Text style={s.rowSub}>{subtitle}</Text> : null}
        {status ? <View style={s.rowChip}><AgentStatusChip status={status} tone={statusTone} /></View> : null}
      </View>
      {right}
      {busy ? (
        <ActivityIndicator color={c.actionBlue} />
      ) : chevron ? (
        <ChevronRight size={agentIcon.small} color={danger ? c.danger : c.muted} strokeWidth={agentIcon.stroke} />
      ) : null}
    </Pressable>
  );
}

/* ------------------------------------------------------------------ */
/* AgentButton — primary / secondary / danger                          */
/* ------------------------------------------------------------------ */
export function AgentButton({
  label,
  onPress,
  variant = "primary",
  icon: Icon,
  loading = false,
  disabled = false,
  style,
  accessibilityLabel,
}: {
  label: string;
  onPress: () => void;
  variant?: "primary" | "secondary" | "danger";
  icon?: LucideIcon;
  loading?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  accessibilityLabel?: string;
}) {
  const fg = variant === "primary" ? c.surface : variant === "danger" ? c.danger : c.actionBlue;
  const off = disabled || loading;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: off, busy: loading }}
      disabled={off}
      onPress={onPress}
      style={({ pressed }) => [
        s.btn,
        variant === "primary" ? s.btnPrimary : variant === "danger" ? s.btnDanger : s.btnSecondary,
        off && s.btnOff,
        pressed && s.pressed,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <>
          {Icon ? <Icon size={agentIcon.action} color={fg} strokeWidth={agentIcon.stroke} /> : null}
          <Text style={[s.btnText, { color: fg }]}>{label}</Text>
        </>
      )}
    </Pressable>
  );
}

/* ------------------------------------------------------------------ */
/* HeritageAccent — one subtle brand-art moment per screen (3–8%)      */
/* ------------------------------------------------------------------ */
const HERITAGE = {
  africa: { src: require("../../../assets/brand/africa_dots_gold.png"), ratio: 176 / 190 },
  pattern: { src: require("../../../assets/brand/tribal_wallpaper.png"), ratio: 1 },
  network: { src: require("../../../assets/brand/network_arcs.png"), ratio: 400 / 368 },
} as const;

export function HeritageAccent({
  variant = "africa",
  size = 140,
  opacity = 0.06,
  style,
}: {
  variant?: keyof typeof HERITAGE;
  size?: number;
  /** Clamped to the spec range 0.03–0.08. */
  opacity?: number;
  /** Position it (usually absolute: { position: "absolute", right: -8, top: -8 }). */
  style?: StyleProp<ViewStyle>;
}) {
  const art = HERITAGE[variant];
  const o = Math.min(0.08, Math.max(0.03, opacity));
  return (
    <View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={[{ position: "absolute", right: 0, top: 0, opacity: o }, style]}
    >
      <Image source={art.src} resizeMode="contain" style={{ width: size * art.ratio, height: size }} />
    </View>
  );
}

/* ------------------------------------------------------------------ */
/* AgentEmptyState                                                     */
/* ------------------------------------------------------------------ */
export function AgentEmptyState({
  icon: Icon,
  title,
  body,
  actionLabel,
  onAction,
}: {
  icon: LucideIcon;
  title: string;
  body: string;
  actionLabel?: string;
  onAction?: () => void;
}) {
  return (
    <AgentCard style={s.empty}>
      <View style={s.emptyIcon}>
        <Icon size={28} color={c.navy} strokeWidth={agentIcon.stroke} />
      </View>
      <Text accessibilityRole="header" style={s.emptyTitle}>{title}</Text>
      <Text style={s.emptyBody}>{body}</Text>
      {actionLabel && onAction ? <AgentButton label={actionLabel} variant="secondary" onPress={onAction} style={s.emptyBtn} /> : null}
    </AgentCard>
  );
}

/* ------------------------------------------------------------------ */
/* AgentSkeleton — pulsing placeholder blocks                          */
/* ------------------------------------------------------------------ */
export function AgentSkeleton({
  rows = 3,
  height = 62,
  style,
}: {
  rows?: number;
  height?: number;
  style?: StyleProp<ViewStyle>;
}) {
  const { t } = useTranslation();
  const pulse = useRef(new Animated.Value(0.5)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 700, useNativeDriver: false }),
        Animated.timing(pulse, { toValue: 0.5, duration: 700, useNativeDriver: false }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);
  return (
    <View accessibilityRole="progressbar" accessibilityLabel={t("loading")} style={[s.skel, style]}>
      {Array.from({ length: rows }).map((_, i) => (
        <Animated.View key={i} style={[s.skelRow, { height, opacity: pulse }]}>
          <View style={s.skelIcon} />
          <View style={{ flex: 1, gap: 8 }}>
            <View style={[s.skelLine, { width: "60%" }]} />
            <View style={[s.skelLine, { width: "35%" }]} />
          </View>
        </Animated.View>
      ))}
    </View>
  );
}

const s = StyleSheet.create({
  pressed: { opacity: 0.85 },
  section: { gap: L.rowGap + 2 },
  sectionHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: 4 },
  sectionLabel: { ...T.caption, color: c.secondary, letterSpacing: 0.6 },
  card: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, overflow: "hidden" },
  cardPad: { padding: L.cardPadding },
  cardDanger: { backgroundColor: c.dangerBg, borderColor: c.dangerBorder },
  chip: {
    height: 26,
    borderRadius: 99,
    paddingHorizontal: 10,
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    alignSelf: "flex-start",
  },
  chipDot: { width: 6, height: 6, borderRadius: 3 },
  chipText: { ...T.caption },
  row: {
    minHeight: L.rowMinHeight,
    flexDirection: "row",
    alignItems: "center",
    gap: 14,
    paddingHorizontal: L.cardPadding,
    paddingVertical: 10,
    backgroundColor: c.surface,
  },
  rowDivider: { borderTopWidth: 1, borderTopColor: c.border },
  rowPressed: { backgroundColor: c.surfaceSoft },
  rowText: { flex: 1, gap: 2 },
  rowTitle: { ...T.cardTitle, color: c.text },
  rowChip: { marginTop: 4 },
  rowSub: { ...T.secondary, color: c.secondary },
  btn: {
    minHeight: L.primaryButtonHeight,
    borderRadius: L.buttonRadius,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    paddingHorizontal: 20,
  },
  btnPrimary: { backgroundColor: c.actionBlue },
  btnSecondary: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.borderStrong },
  btnDanger: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.dangerBorder },
  btnOff: { opacity: 0.5 },
  btnText: { ...T.button },
  empty: { alignItems: "center", gap: 8, paddingVertical: 28 },
  emptyIcon: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: c.blueTint,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 4,
  },
  emptyTitle: { ...T.cardTitle, color: c.heading, textAlign: "center" },
  emptyBody: { ...T.secondary, color: c.secondary, textAlign: "center", maxWidth: 300 },
  emptyBtn: { marginTop: 8, alignSelf: "stretch" },
  skel: { gap: L.rowGap },
  skelRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 14,
    paddingHorizontal: L.cardPadding,
    borderRadius: L.cardRadius,
    borderWidth: 1,
    borderColor: c.border,
    backgroundColor: c.surface,
  },
  skelIcon: { width: 22, height: 22, borderRadius: 6, backgroundColor: c.surfaceSoft },
  skelLine: { height: 10, borderRadius: 5, backgroundColor: c.surfaceSoft },
});
