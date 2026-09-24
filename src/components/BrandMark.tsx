import React from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { colors, type } from "@/theme/tokens";

const logo = require("../../assets/icon.png");

/**
 * The OpesInsure mark (the app-icon logo asset) with the wordmark.
 * `size` scales the mark; `compact` hides the wordmark; `inverse` is for
 * dark surfaces.
 */
export function BrandMark({
  compact = false,
  inverse = false,
  size = 36,
}: {
  compact?: boolean;
  inverse?: boolean;
  size?: number;
}) {
  const ink = inverse ? colors.white : colors.navy950;
  return (
    <View
      style={styles.row}
      accessible
      accessibilityRole="image"
      accessibilityLabel="OpesInsure"
    >
      <Image
        source={logo}
        style={{ width: size, height: size, borderRadius: size * 0.22 }}
        resizeMode="contain"
      />
      {!compact && (
        <Text style={[styles.name, { color: ink, fontSize: Math.max(20, size * 0.55) }]}>
          Opes<Text style={styles.blue}>Insure</Text>
        </Text>
      )}
    </View>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 10 },
  name: { ...type.cardTitle, lineHeight: undefined },
  blue: { color: colors.blue600 },
});
