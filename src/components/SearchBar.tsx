import React from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { Search, SlidersHorizontal, X } from "lucide-react-native";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * The one filter control of a page: square SlidersHorizontal button with the
 * active-count badge. Opens the page's FiltersSheet. Used beside the search
 * field, or alone on pages without a search (offers, comparisons).
 */
export function FilterButton({ onPress, label, count = 0, inset }: { onPress: () => void; label?: string; count?: number; inset?: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={count ? `${label ?? ""} (${count})` : label}
      onPress={onPress}
      style={({ pressed }) => [inset ? styles.filterInset : styles.filter, count > 0 && styles.filterOn, pressed && styles.pressed]}
    >
      <SlidersHorizontal size={22} color={colors.navy900} />
      {count ? (
        <View style={styles.badge}>
          <Text style={styles.badgeText}>{count > 9 ? "9+" : count}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

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
  inset,
  filled,
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
  /** Renders the filter button inside the field (Home design) instead of beside it. */
  inset?: boolean;
  /** Soft grey fill without a border (Explore design). */
  filled?: boolean;
}) {
  const filterButton = onFilter ? <FilterButton onPress={onFilter} label={filterLabel} count={filterCount} inset={inset} /> : null;
  const bar = (
    <View style={[styles.bar, filled && styles.filled, onFilter ? styles.flex : null]}>
      <Search size={20} color={colors.neutral600} style={{ flexShrink: 0 }} />
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
      {inset ? filterButton : null}
    </View>
  );
  if (!onFilter || inset) return bar;
  return (
    <View style={styles.row}>
      {bar}
      {filterButton}
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
  input: { ...type.body, flex: 1, minWidth: 0, color: colors.navy950, paddingVertical: space.x3 },
  flex: { flex: 1 },
  filled: { backgroundColor: "#F2F5FB", borderColor: "#F2F5FB" },
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
  filterInset: {
    width: 44,
    height: 44,
    marginRight: -space.x2,
    borderRadius: radius.control,
    backgroundColor: colors.neutral100,
    alignItems: "center",
    justifyContent: "center",
  },
  filterOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  pressed: { opacity: 0.85 },
  badge: { position: "absolute", top: -6, right: -6, minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 4, backgroundColor: colors.blue600, alignItems: "center", justifyContent: "center" },
  badgeText: { ...type.caption, fontSize: 11, lineHeight: 14, color: colors.white },
  clear: { width: 32, height: 32, alignItems: "center", justifyContent: "center" },
});
