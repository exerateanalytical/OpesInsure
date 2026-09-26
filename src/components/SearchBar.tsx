import React from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { Search, SlidersHorizontal, X } from "lucide-react-native";
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
  onFilter,
  filterLabel,
  filterCount = 0,
}: {
  value: string;
  onChangeText: (v: string) => void;
  onSubmit?: () => void;
  placeholder: string;
  label: string;
  clearLabel: string;
  autoFocus?: boolean;
  /** Opens the filter sheet; renders the square filter button beside the field. */
  onFilter?: () => void;
  filterLabel?: string;
  /** Active filter count shown as a badge on the filter button. */
  filterCount?: number;
}) {
  const bar = (
    <View style={[styles.bar, onFilter ? styles.flex : null]}>
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
  if (!onFilter) return bar;
  return (
    <View style={styles.row}>
      {bar}
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={filterCount ? `${filterLabel ?? ""} (${filterCount})` : filterLabel}
        onPress={onFilter}
        style={({ pressed }) => [styles.filter, filterCount > 0 && styles.filterOn, pressed && styles.pressed]}
      >
        <SlidersHorizontal size={22} color={colors.navy900} />
        {filterCount ? (
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{filterCount > 9 ? "9+" : filterCount}</Text>
          </View>
        ) : null}
      </Pressable>
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
  flex: { flex: 1 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  filter: {
    width: 52,
    height: 52,
    borderRadius: radius.control,
    borderWidth: 1,
    borderColor: colors.neutral300,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  filterOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  pressed: { opacity: 0.85 },
  badge: { position: "absolute", top: -6, right: -6, minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 4, backgroundColor: colors.blue600, alignItems: "center", justifyContent: "center" },
  badgeText: { ...type.caption, fontSize: 11, lineHeight: 14, color: colors.white },
  clear: { width: 32, height: 32, alignItems: "center", justifyContent: "center" },
});
