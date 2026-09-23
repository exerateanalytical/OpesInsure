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
import { LinearGradient } from "expo-linear-gradient";
import { Eye, EyeOff, LucideIcon } from "lucide-react-native";
import { authColors, authGradients, authIcon, authRadius, authSpace, authType } from "@/theme/authTokens";

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
        <Icon size={authIcon.normal} strokeWidth={authIcon.strokeWidth} color={authColors.slate500} />
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
}: {
  label: string;
  icon?: LucideIcon;
  onPress?: () => void;
  loading?: boolean;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: disabled || loading, busy: loading }}
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [styles.primaryWrap, (pressed || disabled) && styles.pressed]}
    >
      <LinearGradient
        colors={authGradients.primaryButton}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 0 }}
        style={styles.primaryButton}
      >
        {loading ? (
          <ActivityIndicator color={authColors.white} />
        ) : (
          <>
            <Text style={styles.primaryLabel}>{label}</Text>
            {Icon ? <Icon size={20} strokeWidth={authIcon.strokeWidth} color={authColors.white} /> : null}
          </>
        )}
      </LinearGradient>
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
    borderWidth: 1.5,
    borderColor: authColors.ice200,
    backgroundColor: authColors.ice50,
    borderRadius: authRadius.lg,
    paddingHorizontal: authSpace[4],
  },
  fieldError: { borderColor: "#C9363E" },
  input: {
    flex: 1,
    fontSize: 16,
    fontFamily: "Manrope_400Regular",
    color: authColors.navy950,
    paddingVertical: authSpace[3],
  },
  error: { ...authType.label, fontSize: 12, color: "#C9363E" },
  primaryWrap: { borderRadius: authRadius.button, overflow: "hidden" },
  primaryButton: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: authSpace[2],
  },
  primaryLabel: { ...authType.button, color: authColors.white },
  secondaryButton: {
    minHeight: 56,
    borderRadius: authRadius.button,
    borderWidth: 1.5,
    borderColor: authColors.navy800,
    alignItems: "center",
    justifyContent: "center",
  },
  secondaryLabel: { ...authType.button, color: authColors.navy800 },
  pressed: { opacity: 0.85 },
});
