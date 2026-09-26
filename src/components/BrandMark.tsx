import React from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { colors, type } from "@/theme/tokens";

const logo = require("../../assets/icon.png");

/** One tagline for every lockup (splash, onboarding, auth, in-app header). */
export const BRAND_TAGLINE = "INSURANCE FOR A BRIGHTER TOMORROW";
/** Wordmark colours shared by every lockup: navy "Opes" + blue "Insure"
 * (white + light blue on dark surfaces). */
export const WORDMARK = {
  ink: colors.navy950,
  accent: colors.blue600,
  inkInverse: colors.white,
  accentInverse: "#5B9BFF",
} as const;

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
  return (
    <View style={styles.row} accessible accessibilityRole="image" accessibilityLabel="OpesInsure">
      <Image source={logo} style={{ width: size, height: size, borderRadius: size * 0.22 }} resizeMode="contain" />
      {!compact && (
        <Text style={[styles.name, { color: inverse ? WORDMARK.inkInverse : WORDMARK.ink, fontSize: Math.max(20, size * 0.55) }]}>
          Opes<Text style={{ color: inverse ? WORDMARK.accentInverse : WORDMARK.accent }}>Insure</Text>
        </Text>
      )}
    </View>
  );
}

/**
 * Stacked entry lockup used by splash, onboarding and the auth heroes:
 * app icon centred, two-tone wordmark, spaced tagline. The icon keeps a
 * square box (contain) and the text wraps/shrinks instead of clipping.
 */
export function EntryLockup({ iconSize = 112, inverse = false, tagline = BRAND_TAGLINE }: { iconSize?: number; inverse?: boolean; tagline?: string }) {
  const wordSize = Math.round(Math.max(28, Math.min(44, iconSize * 0.36)));
  return (
    <View style={styles.stack} accessible accessibilityRole="image" accessibilityLabel="OpesInsure">
      <Image
        source={logo}
        style={[styles.icon, { width: iconSize, height: iconSize, borderRadius: iconSize * 0.24 }]}
        resizeMode="contain"
        accessibilityIgnoresInvertColors
      />
      <Text
        style={[styles.word, { fontSize: wordSize, lineHeight: Math.round(wordSize * 1.2), color: inverse ? WORDMARK.inkInverse : WORDMARK.ink }]}
        numberOfLines={1}
        adjustsFontSizeToFit
      >
        Opes<Text style={{ color: inverse ? WORDMARK.accentInverse : WORDMARK.accent }}>Insure</Text>
      </Text>
      <Text style={[styles.tagline, inverse && styles.taglineInverse]} numberOfLines={1} adjustsFontSizeToFit>
        {tagline}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 10 },
  name: { ...type.cardTitle, lineHeight: undefined },
  stack: { alignItems: "center", gap: 4, alignSelf: "stretch" },
  icon: {
    marginBottom: 6,
    shadowColor: colors.navy900,
    shadowOpacity: 0.28,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
    elevation: 8,
  },
  word: { fontFamily: "Inter_700Bold", letterSpacing: -0.8, textAlign: "center" },
  tagline: { ...type.eyebrow, fontSize: 10.5, lineHeight: 14, letterSpacing: 2.4, color: colors.navy900, textAlign: "center", paddingHorizontal: 8 },
  taglineInverse: { color: "#DCE7FA" },
});
