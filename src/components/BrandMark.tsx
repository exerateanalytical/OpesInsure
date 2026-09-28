import React from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { colors, type } from "@/theme/tokens";

const logo = require("../../assets/icon.png");
/** The platform logo at header size (256px copy of the app icon). Every in-app
 * lockup uses this so the customer, agent, broker and insurer apps match. */
export const PLATFORM_LOGO = require("../../assets/brand/platform_logo.png");

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
  caption,
  wordSize,
}: {
  compact?: boolean;
  inverse?: boolean;
  size?: number;
  /** Small line under the wordmark (e.g. the portal name in a dashboard header). */
  caption?: string;
  /** Wordmark font size; defaults to scale with `size`. */
  wordSize?: number;
}) {
  const fontSize = wordSize ?? Math.max(20, size * 0.55);
  return (
    <View style={styles.row} accessible accessibilityRole="image" accessibilityLabel={caption ? `OpesInsure, ${caption}` : "OpesInsure"}>
      <Image source={size > 72 ? logo : PLATFORM_LOGO} style={{ width: size, height: size, borderRadius: size * 0.22 }} resizeMode="contain" accessibilityIgnoresInvertColors />
      {!compact && (
        <View style={styles.rowText}>
          <Text style={[styles.name, { color: inverse ? WORDMARK.inkInverse : WORDMARK.ink, fontSize, lineHeight: Math.round(fontSize * 1.2) }]} numberOfLines={1}>
            Opes<Text style={{ color: inverse ? WORDMARK.accentInverse : WORDMARK.accent }}>Insure</Text>
          </Text>
          {caption ? (
            <Text style={[styles.caption, inverse && styles.taglineInverse]} numberOfLines={1}>
              {caption}
            </Text>
          ) : null}
        </View>
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
  row: { flexDirection: "row", alignItems: "center", gap: 10, flexShrink: 1 },
  rowText: { flexShrink: 1 },
  caption: { fontFamily: "Inter_500Medium", fontSize: 12, lineHeight: 16, color: colors.neutral600 },
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
