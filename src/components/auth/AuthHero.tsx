import React, { ReactNode } from "react";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { LinearGradient } from "expo-linear-gradient";
import { authColors, authGradients, authSpace, authType } from "@/theme/tokens";

const icon = require("../../../assets/icon.png");
const africaNetwork = require("../../../assets/auth/africa_network_composite.png");
const saferBrighterAfrica = require("../../../assets/auth/safer_brighter_africa.png");
const tribalCorner = require("../../../assets/auth/tribal_corner_dark.png");

/**
 * The dark navy hero shared by sign-in and sign-up: locked app icon,
 * wordmark, tagline, dotted-Africa/network accent and heritage edge motif.
 * `heading`/`subheading` are per-screen; the rest is identical on both.
 */
export function AuthHero({
  heading,
  subheading,
}: {
  heading: string;
  subheading: string;
}) {
  // Keep the decorative art clear of the centred brand block (92px icon)
  // on narrow 320-360dp phones: the network graphic may extend to at most
  // the icon's right edge, and the badge sits top-left, never on the network.
  const { width } = useWindowDimensions();
  const networkSize = Math.max(110, Math.min(200, width / 2 - 18));
  const badgeSize = width < 360 ? 60 : 84;
  const showBadge = width >= 330;
  return (
    <LinearGradient
      colors={authGradients.navySurface}
      start={{ x: 0.15, y: 0 }}
      end={{ x: 0.9, y: 1 }}
      style={styles.hero}
    >
      <Image source={tribalCorner} style={styles.tribalCorner} resizeMode="contain" />
      <Image
        source={africaNetwork}
        style={[styles.network, { width: networkSize, height: networkSize }]}
        resizeMode="contain"
        accessibilityElementsHidden
        importantForAccessibility="no"
      />
      {showBadge ? (
        <Image
          source={saferBrighterAfrica}
          style={[styles.safer, { width: badgeSize, height: badgeSize }]}
          resizeMode="contain"
          accessibilityElementsHidden
          importantForAccessibility="no"
        />
      ) : null}

      <View style={styles.brandBlock}>
        <Image source={icon} style={styles.icon} resizeMode="contain" />
        <Text style={styles.wordmark}>
          Opes<Text style={styles.wordmarkAccent}>Insure</Text>
        </Text>
        <Text style={styles.tagline}>INSURANCE FOR A BRIGHTER TOMORROW</Text>
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
    paddingTop: authSpace[6],
    paddingBottom: authSpace[9],
    paddingHorizontal: authSpace[5],
    overflow: "hidden",
  },
  tribalCorner: {
    position: "absolute",
    left: -36,
    top: 0,
    width: 120,
    height: "85%",
    opacity: 0.3,
  },
  network: {
    position: "absolute",
    right: -28,
    top: authSpace[4],
    opacity: 0.9,
  },
  safer: {
    position: "absolute",
    left: authSpace[3],
    top: authSpace[3],
  },
  brandBlock: { alignItems: "center", gap: authSpace[2] },
  icon: { width: 92, height: 92, borderRadius: 22 },
  wordmark: { ...authType.h2, color: authColors.white, marginTop: authSpace[1] },
  wordmarkAccent: { color: authColors.azure500 },
  tagline: {
    ...authType.label,
    color: authColors.ice100,
    letterSpacing: 2,
    fontSize: 11,
  },
  copyBlock: { marginTop: authSpace[6], gap: authSpace[2] },
  heading: { ...authType.h1, color: authColors.white },
  subheading: { ...authType.body, color: authColors.ice100 },
  card: {
    marginTop: -authSpace[8],
    backgroundColor: authColors.white,
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    paddingHorizontal: authSpace[5],
    paddingTop: authSpace[6],
    paddingBottom: authSpace[5],
    gap: authSpace[3],
  },
});
