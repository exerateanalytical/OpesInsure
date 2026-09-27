import React, { useState } from "react";
import {
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

/* BTN-001: auth CTAs use the shared <Button variant="brand" | "brandOutline"> (src/components/ui.tsx). */

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
  pressed: { opacity: 0.85 },
});
