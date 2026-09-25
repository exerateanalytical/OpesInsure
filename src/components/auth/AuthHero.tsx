import React, { ReactNode, useCallback } from "react";
import { setStatusBarStyle } from "expo-status-bar";
import { useFocusEffect } from "expo-router";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { LinearGradient } from "expo-linear-gradient";
import { authColors, authGradients, authSpace, authType, colors, type } from "@/theme/tokens";
import { HeritagePattern, KenteBand } from "@/components/HeritagePattern";

const icon = require("../../../assets/icon.png");

/**
 * The indigo hero shared by sign-in, sign-up and verify: kente strip, faint
 * ndop lattice (vector, so it never stretches), app icon, wordmark and the
 * per-screen heading. The icon keeps a 1:1 box with resizeMode contain and
 * shrinks on 360dp phones; text wraps (large fonts) instead of clipping.
 */
export function AuthHero({
  heading,
  subheading,
  compact,
}: {
  heading: string;
  subheading: string;
  /** Smaller brand block for secondary screens (verify, forgot password). */
  compact?: boolean;
}) {
  const { width } = useWindowDimensions();
  // Light status-bar icons over the indigo hero while this screen is focused
  // (the app default is dark icons on the sand canvas).
  useFocusEffect(
    useCallback(() => {
      setStatusBarStyle("light");
      return () => setStatusBarStyle("dark");
    }, []),
  );
  const iconSize = compact ? 52 : width < 360 ? 60 : 76;
  return (
    <LinearGradient
      colors={authGradients.navySurface}
      start={{ x: 0.1, y: 0 }}
      end={{ x: 0.9, y: 1 }}
      style={styles.hero}
    >
      <HeritagePattern variant="ndop" opacity={0.09} />
      <KenteBand height={6} style={styles.band} />
      <View style={[styles.brandRow, compact && styles.brandRowCompact]}>
        <Image
          source={icon}
          style={{ width: iconSize, height: iconSize, borderRadius: iconSize * 0.24 }}
          resizeMode="contain"
          accessibilityIgnoresInvertColors
        />
        <View style={styles.brandText}>
          <Text style={styles.wordmark} numberOfLines={1} adjustsFontSizeToFit>
            Opes<Text style={styles.wordmarkAccent}>Insure</Text>
          </Text>
          <Text style={styles.tagline}>INSURANCE FOR A BRIGHTER TOMORROW</Text>
        </View>
      </View>
      <View style={styles.copyBlock}>
        <Text accessibilityRole="header" style={styles.heading}>{heading}</Text>
        <Text style={styles.subheading}>{subheading}</Text>
      </View>
    </LinearGradient>
  );
}

/** The rounded white surface that overlaps the bottom of the hero. */
export function AuthCard({ children }: { children: ReactNode }) {
  return <View style={styles.card}>{children}</View>;
}

const styles = StyleSheet.create({
  hero: {
    paddingTop: authSpace[5],
    paddingBottom: authSpace[9],
    paddingHorizontal: authSpace[5],
    overflow: "hidden",
  },
  band: { position: "absolute", top: 0, left: 0, right: 0 },
  brandRow: { flexDirection: "row", alignItems: "center", gap: authSpace[3], marginTop: authSpace[2] },
  brandRowCompact: { gap: authSpace[2] },
  brandText: { flex: 1, gap: 2 },
  wordmark: { ...authType.h2, color: authColors.white },
  wordmarkAccent: { color: authColors.azure500 },
  tagline: { ...type.eyebrow, color: colors.gold100 },
  copyBlock: { marginTop: authSpace[5], gap: authSpace[2] },
  heading: { ...authType.h1, color: authColors.white },
  subheading: { ...authType.body, color: authColors.ice100 },
  card: {
    marginTop: -authSpace[6],
    backgroundColor: authColors.white,
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    paddingHorizontal: authSpace[5],
    paddingTop: authSpace[5],
    paddingBottom: authSpace[5],
    gap: authSpace[3],
  },
});
