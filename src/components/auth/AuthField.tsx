import React, { useState } from "react";
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  View,
} from "react-native";
import { Eye, EyeOff, LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authRadius, authSpace, authType } from "@/theme/tokens";

export function AuthTextField({
  icon: Icon,
  error,
  secureToggle = false,
  ...props
}: TextInputProps & {
  icon: LucideIcon;
  error?: string;
  /** Renders an eye/eye-off toggle and manages secureTextEntry itself. */
  secureToggle?: boolean;
}) {
  const [hidden, setHidden] = useState(secureToggle);
  return (
    <View style={styles.fieldWrap}>
      <View style={[styles.field, error && styles.fieldError]}>
        {/* Fixed box: RN-web let the svg shrink to a dot at 360dp. */}
        <View style={styles.iconBox}>
          <Icon size={authIcon.normal} strokeWidth={authIcon.strokeWidth} color={authColors.slate500} />
        </View>
        <TextInput
          accessibilityLabel={props.placeholder}
          placeholderTextColor={authColors.slate500}
          style={styles.input}
          secureTextEntry={secureToggle ? hidden : props.secureTextEntry}
          {...props}
        />
        {secureToggle ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={hidden ? "Show password" : "Hide password"}
            hitSlop={8}
            onPress={() => setHidden((v) => !v)}
          >
            {hidden ? (
              <EyeOff size={authIcon.normal} strokeWidth={authIcon.strokeWidth} color={authColors.slate500} />
            ) : (
              <Eye size={authIcon.normal} strokeWidth={authIcon.strokeWidth} color={authColors.slate500} />
            )}
          </Pressable>
        ) : null}
      </View>
      {error ? (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      ) : null}
    </View>
  );
}

export function AuthPrimaryButton({
  label,
  icon: Icon,
  onPress,
  loading = false,
  disabled = false,
  tone = "blue",
}: {
  label: string;
  icon?: LucideIcon;
  onPress?: () => void;
  loading?: boolean;
  disabled?: boolean;
  /** Solid blue600 by default; "gold" for the gold CTAs of the designs. */
  tone?: "blue" | "gold";
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: disabled || loading, busy: loading }}
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        styles.primaryButton,
        tone === "gold" && styles.primaryGold,
        (pressed || disabled) && styles.pressed,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={authColors.white} />
      ) : (
        <>
          <Text style={styles.primaryLabel}>{label}</Text>
          {Icon ? <Icon size={20} strokeWidth={authIcon.strokeWidth} color={authColors.white} /> : null}
        </>
      )}
    </Pressable>
  );
}

export function AuthSecondaryButton({
  label,
  onPress,
  disabled = false,
}: {
  label: string;
  onPress?: () => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
    >
      <Text style={styles.secondaryLabel}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  fieldWrap: { gap: authSpace[1] },
  field: {
    flexDirection: "row",
    alignItems: "center",
    gap: authSpace[2],
    minHeight: 56,
    borderWidth: 1,
    borderColor: authColors.ice200,
    backgroundColor: authColors.white,
    borderRadius: authRadius.lg,
    paddingHorizontal: authSpace[4],
  },
  fieldError: { borderColor: authColors.danger },
  iconBox: { flexShrink: 0 },
  input: {
    minWidth: 0,
    flex: 1,
    fontSize: 16,
    fontFamily: "Inter_400Regular",
    color: authColors.navy950,
    paddingVertical: authSpace[3],
  },
  error: { ...authType.label, fontSize: 12, color: authColors.dangerText },
  primaryButton: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: authSpace[2],
    borderRadius: authRadius.button,
    backgroundColor: authColors.blue500,
  },
  primaryGold: { backgroundColor: authColors.gold500 },
  primaryLabel: { ...authType.button, color: authColors.white },
  secondaryButton: {
    minHeight: 56,
    borderRadius: authRadius.button,
    borderWidth: 1.5,
    borderColor: authColors.blue500,
    backgroundColor: authColors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  secondaryLabel: { ...authType.button, color: authColors.blue500 },
  pressed: { opacity: 0.85 },
});
