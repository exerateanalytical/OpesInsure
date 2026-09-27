import React, { ReactNode, useState } from "react";
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleProp,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  View,
  ViewStyle,
} from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { LucideIcon } from "lucide-react-native";
import { CONTENT_MAX_WIDTH, colors, radius, space, type } from "@/theme/tokens";
import { formatXaf, useTranslation } from "@/i18n";
import { BrandHeader } from "@/components/design";

export function Screen({
  children,
  scroll = true,
  style,
  footer,
}: {
  children: ReactNode;
  scroll?: boolean;
  style?: StyleProp<ViewStyle>;
  /** Pinned below the scroll area (e.g. a portal bottom bar). */
  footer?: ReactNode;
}) {
  const insets = useSafeAreaInsets();
  // Without a footer the screen owns the bottom inset so the last button is
  // never hidden under the Android nav bar / iOS home indicator.
  const bottom = footer ? 0 : insets.bottom;
  // Without its own ScrollView the body fills the screen, so a virtualized
  // list (FlatList) can take the remaining height and scroll by itself.
  // Content is capped at CONTENT_MAX_WIDTH and centred on tablets/foldables
  // so cards do not stretch edge to edge on wide screens.
  const body = <View style={[styles.screenBody, styles.contentWidth, !scroll && styles.flex, style]}>{children}</View>;
  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        {scroll ? (
          <ScrollView
            style={styles.flex}
            contentContainerStyle={[
              styles.scroll,
              { paddingBottom: space.x16 + bottom },
            ]}
            showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="interactive"
          >
            {body}
          </ScrollView>
        ) : (
          <View style={[styles.flex, { paddingBottom: bottom }]}>{body}</View>
        )}
        {footer}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

export { CONTENT_MAX_WIDTH } from "@/theme/tokens";

/** Android ripple for pressable surfaces; iOS/web fall back to the opacity press style. */
export const ripple = (dark = false) => ({ color: dark ? "rgba(255,255,255,0.18)" : "rgba(15,21,53,0.10)", borderless: false });

/**
 * Page header. Since design v3 this renders the brand header (logo lockup,
 * Africa network art, page title/subtitle) so every screen still using
 * AppHeader picks the new look up without edits. `action` renders where the
 * design puts the bell (top-right); pass nothing for a plain header.
 */
export function AppHeader({
  title,
  subtitle,
  back = false,
  action,
}: {
  title: string;
  subtitle?: string;
  back?: boolean;
  action?: ReactNode;
}) {
  return <BrandHeader title={title} subtitle={subtitle} back={back} right={action ?? null} />;
}

/** Three woven segments (ochre, terracotta, indigo): the kente signature
 * under every page title. Decorative, hidden from screen readers. */
export function HeritageAccent({ style }: { style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[styles.accent, style]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <View style={[styles.accentSeg, { backgroundColor: colors.gold500, width: 22 }]} />
      <View style={[styles.accentSeg, { backgroundColor: colors.terracotta500 }]} />
      <View style={[styles.accentSeg, { backgroundColor: colors.navy800 }]} />
    </View>
  );
}

export function Card({
  children,
  style,
  feature = false,
  onPress,
  accessibilityLabel,
}: {
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
  feature?: boolean;
  /** Makes the whole card a native pressable (ripple on Android). */
  onPress?: () => void;
  accessibilityLabel?: string;
}) {
  if (onPress) {
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        onPress={onPress}
        android_ripple={ripple()}
        style={({ pressed }) => [styles.card, feature && styles.featureCard, pressed && styles.cardPressed, style]}
      >
        {children}
      </Pressable>
    );
  }
  return (
    <View style={[styles.card, feature && styles.featureCard, style]}>
      {children}
    </View>
  );
}

export function Button({
  label,
  onPress,
  icon: Icon,
  variant = "primary",
  loading = false,
  disabled = false,
  size = "default",
}: {
  label: string;
  onPress?: () => void;
  icon?: LucideIcon;
  /** tertiary = ghost (text only). */
  variant?: "primary" | "secondary" | "tertiary" | "danger";
  loading?: boolean;
  disabled?: boolean;
  /** small = 48dp (still a full touch target); default = 52dp. */
  size?: "default" | "small";
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled, busy: loading }}
      disabled={disabled || loading}
      onPress={onPress}
      android_ripple={ripple(variant === "primary")}
      style={({ pressed }) => [
        styles.button,
        size === "small" && styles.buttonSmall,
        styles[`button_${variant}`],
        pressed && styles.pressed,
        disabled && styles.disabled,
      ]}
    >
      {loading ? (
        <ActivityIndicator
          color={variant === "primary" ? colors.white : colors.blue600}
        />
      ) : (
        <>
          {Icon ? (
            <Icon
              size={20}
              color={
                variant === "primary"
                  ? colors.white
                  : variant === "danger"
                    ? colors.danger
                    : colors.blue600
              }
            />
          ) : null}
          <Text allowFontScaling maxFontSizeMultiplier={1.8} style={[styles.buttonLabel, styles[`buttonLabel_${variant}`]]}>
            {label}
          </Text>
        </>
      )}
    </Pressable>
  );
}

export function TextField({
  label,
  error,
  hint,
  ...props
}: TextInputProps & { label: string; error?: string; hint?: string }) {
  const [focused, setFocused] = useState(false);
  const disabled = props.editable === false;
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        accessibilityHint={hint}
        accessibilityState={{ disabled }}
        placeholderTextColor={colors.neutral500}
        selectionColor={colors.blue600}
        {...props}
        onFocus={(e) => {
          setFocused(true);
          props.onFocus?.(e);
        }}
        onBlur={(e) => {
          setFocused(false);
          props.onBlur?.(e);
        }}
        style={[
          styles.input,
          props.multiline && styles.inputMultiline,
          focused && styles.inputFocused,
          error && styles.inputError,
          disabled && styles.inputDisabled,
          props.style,
        ]}
      />
      {error ? (
        <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.error}>{error}</Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

export function StatusChip({
  label,
  tone = "neutral",
}: {
  label: string;
  tone?: "neutral" | "success" | "warning" | "info" | "danger";
}) {
  const palette = {
    neutral: [colors.neutral100, colors.neutral700],
    success: [colors.successSoft, colors.successText],
    warning: [colors.warningSoft, colors.warningText],
    info: [colors.blue50, colors.blue700],
    danger: [colors.dangerSoft, colors.dangerText],
  }[tone];
  return (
    <View style={[styles.chip, { backgroundColor: palette[0] }]}>
      <Text style={[styles.chipText, { color: palette[1] }]}>{label}</Text>
    </View>
  );
}

/** Selectable filter chip (sort, status and category filters). */
export function Chip({
  label,
  selected,
  onPress,
  role = "button",
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
  /** "tab" inside an accessibilityRole="tablist" row of exclusive filters. */
  role?: "button" | "tab";
}) {
  return (
    <Pressable
      accessibilityRole={role}
      accessibilityState={{ selected }}
      onPress={onPress}
      android_ripple={ripple(selected)}
      style={({ pressed }) => [styles.chipSelect, selected && styles.chipSelectOn, pressed && styles.pressed]}
    >
      <Text allowFontScaling maxFontSizeMultiplier={1.6} style={[styles.chipSelectText, selected && styles.chipSelectTextOn]}>
        {label}
      </Text>
    </Pressable>
  );
}

/** A wrapping row of Chips; `exclusive` marks it as a tablist. */
export function ChipRow({ children, exclusive = false, style }: { children: ReactNode; exclusive?: boolean; style?: StyleProp<ViewStyle> }) {
  return (
    <View accessibilityRole={exclusive ? "tablist" : undefined} style={[styles.chipRow, style]}>
      {children}
    </View>
  );
}

export function SectionTitle({
  title,
  action,
}: {
  title: string;
  action?: ReactNode;
}) {
  return (
    <View style={styles.sectionRow}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {action}
    </View>
  );
}
export function Money({
  amount,
  size = "normal",
}: {
  amount: number;
  size?: "normal" | "large";
}) {
  const { language } = useTranslation();
  const text = formatXaf(amount, language);
  return (
    <Text accessibilityLabel={text} style={size === "large" ? styles.moneyLarge : styles.money}>
      {text}
    </Text>
  );
}

/**
 * One measurement for every form control (TextField, SelectField,
 * MasterSelectField, DateField, Button): 52dp high, 12 radius, 16dp inner
 * padding, 14/19 semibold label 8dp above, 16/24 value text.
 */
export const FIELD = {
  height: 52,
  radius: 12,
  padX: space.x4,
  labelGap: space.x2,
  /** Focus/error ring drawn outside the border (web outline, native shadow-free inset). */
  ring: (color: string) =>
    Platform.OS === "web"
      ? ({ outlineColor: color, outlineWidth: 1, outlineStyle: "solid" } as unknown as { borderWidth?: number })
      : { borderWidth: 2 },
} as const;

/** Shared look of a select trigger so pickers match TextField exactly. */
export const fieldStyles = StyleSheet.create({
  field: { gap: FIELD.labelGap },
  label: { ...type.label, color: colors.neutral800 },
  control: {
    minHeight: FIELD.height,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: FIELD.radius,
    backgroundColor: colors.white,
    paddingHorizontal: FIELD.padX,
    paddingVertical: space.x3 - 2,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
  },
  controlPressed: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  controlError: { borderColor: colors.danger, ...FIELD.ring(colors.danger) },
  controlDisabled: { backgroundColor: colors.neutral100, borderColor: colors.neutral200 },
  value: { ...type.body, color: colors.navy950, flex: 1 },
  placeholder: { color: colors.neutral500 },
  disabledText: { color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  hint: { ...type.meta, color: colors.neutral600 },
});

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  flex: { flex: 1 },
  scroll: { flexGrow: 1 },
  screenBody: { paddingHorizontal: space.x5, gap: space.x6 },
  contentWidth: { width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  cardPressed: { opacity: 0.9, borderColor: colors.neutral300 },
  header: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingTop: space.x2,
  },
  headerCopy: { flex: 1, gap: 2 },
  headerTitle: { ...type.pageTitle, color: colors.navy950 },
  headerSubtitle: { ...type.meta, color: colors.neutral600 },
  accent: { flexDirection: "row", gap: 3, marginTop: space.x1 },
  accentSeg: { height: 3, width: 10, borderRadius: 2 },
  iconButton: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
  },
  card: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
    gap: space.x3,
  },
  // Feature cards carry an ochre top edge and a soft indigo shadow.
  featureCard: {
    borderRadius: radius.feature,
    padding: space.x5,
    borderTopWidth: 3,
    borderTopColor: colors.gold500,
    shadowColor: colors.navy950,
    shadowOpacity: 0.06,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2,
  },
  button: {
    minHeight: FIELD.height,
    overflow: "hidden",
    borderRadius: FIELD.radius,
    paddingHorizontal: space.x4,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
    borderWidth: 1,
  },
  button_primary: {
    backgroundColor: colors.blue600,
    borderColor: colors.blue600,
  },
  button_secondary: {
    backgroundColor: colors.white,
    borderColor: colors.neutral300,
  },
  button_tertiary: {
    backgroundColor: "transparent",
    borderColor: "transparent",
  },
  button_danger: {
    backgroundColor: colors.dangerSoft,
    borderColor: colors.danger,
  },
  buttonSmall: { minHeight: 48 },
  pressed: { opacity: 0.82 },
  disabled: { opacity: 0.48 },
  buttonLabel: { ...type.label },
  buttonLabel_primary: { color: colors.white },
  buttonLabel_secondary: { color: colors.navy950 },
  buttonLabel_tertiary: { color: colors.blue600 },
  buttonLabel_danger: { color: colors.dangerText },
  field: { gap: space.x2 },
  label: { ...type.label, color: colors.neutral800 },
  input: {
    minHeight: FIELD.height,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: FIELD.radius,
    backgroundColor: colors.white,
    paddingHorizontal: FIELD.padX,
    paddingVertical: 0,
    ...type.body,
    color: colors.navy950,
  },
  inputMultiline: { paddingVertical: space.x3 },
  // Focus/error borders keep a 1dp width and use a same-colour ring so the text never shifts.
  inputFocused: { borderColor: colors.blue600, ...FIELD.ring(colors.blue600) },
  inputError: { borderColor: colors.danger, ...FIELD.ring(colors.danger) },
  inputDisabled: { backgroundColor: colors.neutral100, color: colors.neutral600, borderColor: colors.neutral200 },
  error: { ...type.meta, color: colors.dangerText },
  hint: { ...type.meta, color: colors.neutral600 },
  chip: {
    alignSelf: "flex-start",
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  chipText: { ...type.caption },
  chipRow: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  chipSelect: {
    minHeight: 40,
    overflow: "hidden",
    paddingHorizontal: space.x4,
    justifyContent: "center",
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.neutral300,
    backgroundColor: colors.white,
  },
  chipSelectOn: { backgroundColor: colors.navy950, borderColor: colors.navy950 },
  chipSelectText: { ...type.label, color: colors.neutral700 },
  chipSelectTextOn: { color: colors.white },
  sectionRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: space.x3,
  },
  sectionTitle: { ...type.sectionTitle, color: colors.navy950 },
  money: {
    ...type.label,
    color: colors.navy950,
    fontVariant: ["tabular-nums"],
  },
  moneyLarge: {
    fontFamily: "Inter_700Bold",
    fontSize: 28,
    lineHeight: 34,
    color: colors.navy950,
    fontVariant: ["tabular-nums"],
  },
});
