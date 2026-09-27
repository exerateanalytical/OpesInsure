import React from "react";
import { Pressable, ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import type { LucideIcon } from "lucide-react-native";
import { CATEGORIES, type Category } from "@/components/customer/categories";
import { CONTENT_MAX_WIDTH, ripple } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/** Tint per category, as in the reference designs (motor gold, health red, travel blue, home green, others indigo). */
export const CATEGORY_TINT: Record<Category["id"], { bg: string; fg: string }> = {
  motor: { bg: colors.gold50, fg: colors.navy900 },
  health: { bg: colors.dangerSoft, fg: colors.danger },
  travel: { bg: colors.blue50, fg: colors.blue600 },
  home: { bg: colors.successSoft, fg: colors.navy900 },
  business: { bg: colors.blue100, fg: colors.navy900 },
  life: { bg: colors.blue50, fg: colors.navy900 },
  accident: { bg: colors.gold50, fg: colors.gold600 },
  more: { bg: colors.neutral100, fg: colors.navy900 },
};

/** Tinted square + label, e.g. the category strip on Home and Explore. */
export function CategoryTile({ category, onPress, size = 64 }: { category: Category; onPress: () => void; size?: number }) {
  const { t } = useTranslation();
  const Icon: LucideIcon = category.icon;
  const tint = CATEGORY_TINT[category.id];
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${t(category.label)}. ${t(category.caption)}`}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [styles.tileWrap, pressed && styles.pressed]}
    >
      <View style={[styles.tile, { width: size, height: size, backgroundColor: tint.bg }]}>
        <Icon size={Math.round(size * 0.44)} color={tint.fg} strokeWidth={2} />
      </View>
      <Text style={styles.label} numberOfLines={1}>{t(category.label)}</Text>
    </Pressable>
  );
}

/** Horizontal strip of category tiles. `ids` limits and orders them. */
export function CategoryStrip({ ids, onPress }: { ids: Category["id"][]; onPress: (c: Category) => void }) {
  const items = ids.map((id) => CATEGORIES.find((c) => c.id === id)).filter((c): c is Category => !!c);
  const width = Math.min(useWindowDimensions().width, CONTENT_MAX_WIDTH) - space.x5 * 2;
  // Up to five tiles share the row evenly (no clipped tile at 360dp); more scroll.
  if (items.length <= 5) {
    const size = Math.max(48, Math.min(64, Math.floor((width - space.x2 * (items.length - 1)) / items.length)));
    return (
      <View style={styles.row}>
        {items.map((c) => (
          <View key={c.id} style={styles.cell}>
            <CategoryTile category={c} size={size} onPress={() => onPress(c)} />
          </View>
        ))}
      </View>
    );
  }
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.strip}>
      {items.map((c) => (
        <CategoryTile key={c.id} category={c} onPress={() => onPress(c)} />
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  strip: { gap: space.x3, paddingVertical: 2 },
  row: { flexDirection: "row", justifyContent: "space-between", paddingVertical: 2 },
  cell: { flexBasis: 0, flexGrow: 1, alignItems: "center" },
  tileWrap: { alignItems: "center", gap: 6, minWidth: 48, borderRadius: radius.card, overflow: "hidden" },
  tile: { borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  label: { ...type.label, color: colors.navy950 },
  pressed: { opacity: 0.85 },
});
