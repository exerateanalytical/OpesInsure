import React, { ReactNode, useCallback } from "react";
import { setStatusBarStyle } from "expo-status-bar";
import { router, useFocusEffect } from "expo-router";
import { Image, Pressable, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { ArrowLeft } from "lucide-react-native";
import { authColors, authSpace, authType, colors, type } from "@/theme/tokens";
import { KenteBand } from "@/components/HeritagePattern";
import { useTranslation } from "@/i18n";

const icon = require("../../../assets/icon.png");
const mapNetwork = require("../../../assets/brand/map_network.png");
const edgeLeft = require("../../../assets/brand/edge_left.png");
const edgeRight = require("../../../assets/brand/edge_right.png");

/**
 * The light design-system hero shared by sign-in, sign-up, verify and forgot
 * password: faint geometric borders on both edges, the dotted-Africa network
 * art in the top-right corner, the app icon + two-tone wordmark lockup and the
 * per-screen heading. The icon keeps a 1:1 box with resizeMode contain and
 * shrinks on 360dp phones; text wraps (large fonts) instead of clipping.
 */
export function AuthHero({
  heading,
  subheading,
  compact,
  back = false,
  dark = false,
}: {
  heading: string;
  subheading: string;
  /** Smaller brand block for secondary screens (verify, forgot password). */
  compact?: boolean;
  /** Show a back arrow above the lockup (secondary screens). */
  back?: boolean;
  /** Dark (indigo) variant: light status-bar icons while focused. The default
   * light canvas keeps the app's dark icons. */
  dark?: boolean;
}) {
  const { width } = useWindowDimensions();
  const { t } = useTranslation();
  useFocusEffect(
    useCallback(() => {
      if (dark) {
        setStatusBarStyle("light");
        return () => setStatusBarStyle("dark");
      }
      setStatusBarStyle("dark");
      return undefined;
    }, [dark]),
  );
  const iconSize = compact ? 52 : width < 360 ? 60 : 72;
  const artSize = Math.min(compact ? 120 : 150, width * 0.4);
  return (
    <View style={[styles.hero, dark && styles.heroDark]}>
      <View
        style={StyleSheet.absoluteFill}
        pointerEvents="none"
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
      >
        <Image source={edgeLeft} style={[styles.edge, styles.edgeLeft]} resizeMode="cover" />
        <Image source={edgeRight} style={[styles.edge, styles.edgeRight]} resizeMode="cover" />
        <Image source={mapNetwork} style={[styles.art, { width: artSize, height: artSize }]} resizeMode="contain" />
      </View>
      <KenteBand height={3} style={styles.band} />
      {back ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t("back")}
          hitSlop={8}
          onPress={() => (router.canGoBack() ? router.back() : router.replace("/(auth)/sign-in"))}
          style={({ pressed }) => [styles.back, pressed && styles.pressed]}
        >
          <ArrowLeft size={22} color={dark ? colors.white : authColors.navy900} strokeWidth={2} />
        </Pressable>
      ) : null}
      <View style={[styles.brandRow, compact && styles.brandRowCompact]}>
        <Image
          source={icon}
          style={{ width: iconSize, height: iconSize, borderRadius: iconSize * 0.24 }}
          resizeMode="contain"
          accessibilityIgnoresInvertColors
        />
        <View style={styles.brandText}>
          <Text style={[styles.wordmark, dark && styles.onDark]} numberOfLines={1} adjustsFontSizeToFit>
            Opes<Text style={[styles.wordmarkAccent, dark && styles.onDarkAccent]}>Insure</Text>
          </Text>
          <Text style={[styles.tagline, dark && styles.taglineDark]}>INSURANCE FOR A BRIGHTER TOMORROW</Text>
        </View>
      </View>
      <View style={styles.copyBlock}>
        <Text accessibilityRole="header" style={[styles.heading, dark && styles.onDark]}>{heading}</Text>
        <Text style={[styles.subheading, dark && styles.subheadingDark]}>{subheading}</Text>
      </View>
    </View>
  );
}

/** The white card that carries the form, over the light canvas. */
export function AuthCard({ children }: { children: ReactNode }) {
  return <View style={styles.card}>{children}</View>;
}

const styles = StyleSheet.create({
  hero: {
    paddingTop: authSpace[4],
    paddingBottom: authSpace[5],
    paddingHorizontal: authSpace[5],
    backgroundColor: authColors.canvas,
    overflow: "hidden",
  },
  heroDark: { backgroundColor: authColors.navy950 },
  band: { position: "absolute", top: 0, left: 0, right: 0, opacity: 0.6 },
  edge: { position: "absolute", top: 0, height: "100%", width: 28, opacity: 0.3 },
  edgeLeft: { left: 0 },
  edgeRight: { right: 0 },
  art: { position: "absolute", top: -authSpace[3], right: -authSpace[5], opacity: 0.55 },
  back: { width: 44, height: 44, alignItems: "center", justifyContent: "center", marginLeft: -authSpace[3], marginBottom: authSpace[1] },
  pressed: { opacity: 0.7 },
  brandRow: { flexDirection: "row", alignItems: "center", gap: authSpace[3], marginTop: authSpace[2] },
  brandRowCompact: { gap: authSpace[2] },
  brandText: { flex: 1, gap: 2, paddingRight: authSpace[6] },
  wordmark: { ...authType.h2, color: authColors.navy950 },
  wordmarkAccent: { color: authColors.azure500 },
  onDark: { color: colors.white },
  onDarkAccent: { color: colors.gold500 },
  tagline: { ...type.eyebrow, fontSize: 9.5, lineHeight: 13, color: authColors.navy900, letterSpacing: 1.4 },
  taglineDark: { color: colors.gold100 },
  copyBlock: { marginTop: authSpace[5], gap: authSpace[1] },
  heading: { ...authType.h1, color: authColors.navy950 },
  subheading: { ...authType.body, color: authColors.textSecondary },
  subheadingDark: { color: authColors.ice100 },
  card: {
    marginHorizontal: authSpace[4],
    marginTop: -authSpace[2],
    backgroundColor: authColors.white,
    borderRadius: 24,
    borderWidth: 1,
    borderColor: authColors.ice200,
    paddingHorizontal: authSpace[4],
    paddingTop: authSpace[5],
    paddingBottom: authSpace[5],
    gap: authSpace[3],
    shadowColor: colors.navy900,
    shadowOpacity: 0.08,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 3,
  },
});
