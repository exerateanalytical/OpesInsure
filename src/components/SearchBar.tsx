import React from "react";
import { Pressable, StyleSheet, TextInput, View } from "react-native";
import { Search, X } from "lucide-react-native";
import { colors, radius, space, type } from "@/theme/tokens";

/** Search input used on Home and Explore. Tapping it when `onPress` is set
 * acts as a link (Home hands the query to Explore). */
export function SearchBar({
  value,
  onChangeText,
  onSubmit,
  placeholder,
  label,
  clearLabel,
  autoFocus,
}: {
  value: string;
  onChangeText: (v: string) => void;
  onSubmit?: () => void;
  placeholder: string;
  label: string;
  clearLabel: string;
  autoFocus?: boolean;
}) {
  return (
    <View style={styles.bar}>
      <Search size={20} color={colors.neutral600} />
      <TextInput
        accessibilityLabel={label}
        value={value}
        onChangeText={onChangeText}
        onSubmitEditing={onSubmit}
        returnKeyType="search"
        placeholder={placeholder}
        placeholderTextColor={colors.neutral500}
        autoFocus={autoFocus}
        autoCorrect={false}
        style={styles.input}
      />
      {value ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={clearLabel}
          hitSlop={10}
          onPress={() => onChangeText("")}
          style={styles.clear}
        >
          <X size={18} color={colors.neutral600} />
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    minHeight: 50,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    paddingHorizontal: space.x4,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
  },
  input: { ...type.body, flex: 1, color: colors.navy950, paddingVertical: space.x3 },
  clear: { width: 32, height: 32, alignItems: "center", justifyContent: "center" },
});
