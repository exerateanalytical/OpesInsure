import React, { ReactNode } from "react";
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
import { ChevronLeft, LucideIcon } from "lucide-react-native";
import { useRouter } from "expo-router";
import { colors, radius, space, type } from "@/theme/tokens";
import { formatXaf, useTranslation } from "@/i18n";

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
  const body = <View style={[styles.screenBody, !scroll && styles.flex, style]}>{children}</View>;
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
  const router = useRouter();
  const { t } = useTranslation();
  return (
    <View style={styles.header}>
      {back && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t("back")}
          hitSlop={8}
          onPress={() => router.back()}
          style={styles.iconButton}
        >
          <ChevronLeft size={22} color={colors.navy950} />
        </Pressable>
      )}
      <View style={styles.headerCopy}>
        <Text accessibilityRole="header" allowFontScaling maxFontSizeMultiplier={1.8} style={styles.headerTitle}>{title}</Text>
        {subtitle ? (
          <Text style={styles.headerSubtitle}>{subtitle}</Text>
        ) : null}
      </View>
      {action}
    </View>
  );
}

export function Card({
  children,
  style,
  feature = false,
}: {
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
  feature?: boolean;
}) {
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
}: {
  label: string;
  onPress?: () => void;
  icon?: LucideIcon;
  variant?: "primary" | "secondary" | "tertiary" | "danger";
  loading?: boolean;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled, busy: loading }}
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        styles.button,
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
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        accessibilityHint={hint}
        accessibilityState={{ disabled: props.editable === false }}
        placeholderTextColor={colors.neutral500}
        style={[styles.input, error && styles.inputError]}
        {...props}
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

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  flex: { flex: 1 },
  scroll: { flexGrow: 1 },
  screenBody: { paddingHorizontal: space.x5, gap: space.x6 },
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
  featureCard: { borderRadius: radius.feature, padding: space.x5 },
  button: {
    minHeight: 50,
    borderRadius: radius.control,
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
    height: 50,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    backgroundColor: colors.white,
    paddingHorizontal: space.x3,
    fontSize: 16,
    fontFamily: "Inter_400Regular",
    color: colors.navy950,
  },
  inputError: { borderColor: colors.danger, borderWidth: 1.5 },
  error: { ...type.meta, color: colors.dangerText },
  hint: { ...type.meta, color: colors.neutral600 },
  chip: {
    alignSelf: "flex-start",
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  chipText: { ...type.caption },
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
