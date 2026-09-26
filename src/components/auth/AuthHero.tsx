import React, { ReactNode, useCallback } from "react";
import { setStatusBarStyle } from "expo-status-bar";
import { router, useFocusEffect } from "expo-router";
import { Image, Pressable, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { LinearGradient } from "expo-linear-gradient";
import { ArrowLeft } from "lucide-react-native";
import { authColors, authSpace, authType, colors } from "@/theme/tokens";
import { EntryLockup } from "@/components/BrandMark";
import { KenteBand } from "@/components/HeritagePattern";
import { useTranslation } from "@/i18n";

const mapNetwork = require("../../../assets/brand/map_network.png");
const scriptTagline = require("../../../assets/brand/script_tagline.png");
const edgeLeft = require("../../../assets/brand/edge_left.png");
const edgeRight = require("../../../assets/brand/edge_right.png");

/** Royal-blue gradient of the blue/gold login and account-creation designs. */
const DARK_GRADIENT = ["#0A2A8C", "#0B3AB4", "#0D47C9"] as const;

/**
 * The hero shared by sign-in, sign-up, verify and forgot password: faint
 * geometric borders on both edges, the dotted-Africa network art + "A Safer
 * Brighter Africa" script in the top-right corner (kept clear of the centred
 * lockup), the shared EntryLockup, the heading and the subheading. `dark`
 * is the royal-blue variant of the login / account-creation designs; the
 * form card then overlaps the hero's lower edge. `children` render under the
 * copy (e.g. the account-type selector on sign-up).
 */
export function AuthHero({
  heading,
  subheading,
  compact,
  back = false,
  dark = false,
  children,
}: {
  heading: string;
  subheading: string;
  /** Smaller brand block for secondary screens (verify, forgot password). */
  compact?: boolean;
  /** Show a back arrow above the lockup (secondary screens). */
  back?: boolean;
  /** Dark (royal blue) variant: light status-bar icons while focused. */
  dark?: boolean;
  children?: ReactNode;
}) {
  const { width, fontScale } = useWindowDimensions();
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
  const small = width < 380 || fontScale > 1.2;
  const iconSize = compact ? 64 : small ? 84 : 104;
  // The art sits in the top-right corner; its box stops short of the centred
  // icon (icon half-width + 8dp) so it never covers the lockup.
  // ...and ends at the icon's bottom edge so it never reaches the wordmark.
  const artSize = Math.max(0, Math.min(compact ? 120 : 170, iconSize + 16, width / 2 - iconSize / 2 - 8));
  return (
    <View style={[styles.hero, dark && styles.heroDark, children ? styles.heroWithChildren : null]}>
      {dark ? <LinearGradient colors={DARK_GRADIENT} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} /> : null}
      <View style={StyleSheet.absoluteFill} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Image source={edgeLeft} style={[styles.edge, styles.edgeLeft, dark && styles.edgeDark]} resizeMode="cover" />
        <Image source={edgeRight} style={[styles.edge, styles.edgeRight, dark && styles.edgeDark]} resizeMode="cover" />
        {artSize >= 80 ? (
          <View style={[styles.art, { width: artSize, height: artSize }]}>
            <Image source={mapNetwork} style={{ width: artSize * 0.78, height: artSize * 0.78, opacity: dark ? 0.8 : 0.9 }} resizeMode="contain" />
            <Image source={scriptTagline} style={[styles.script, { width: artSize * 0.36, height: artSize * 0.36 }, dark && styles.scriptDark]} resizeMode="contain" />
          </View>
        ) : null}
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
      <View style={[styles.brand, back && styles.brandAfterBack]}>
        <EntryLockup iconSize={iconSize} inverse={dark} tagline={t("splashTagline")} />
      </View>
      <View style={styles.copyBlock}>
        <Text accessibilityRole="header" style={[styles.heading, compact && styles.headingCompact, dark && styles.onDark]}>{heading}</Text>
        <Text style={[styles.subheading, dark && styles.subheadingDark]}>{subheading}</Text>
      </View>
      {children ? <View style={styles.children}>{children}</View> : null}
    </View>
  );
}

/** The white form card. Over the dark hero it rises into the hero's lower
 * edge with the large rounded top of the designs. */
export function AuthCard({ children }: { children: ReactNode }) {
  return <View style={styles.card}>{children}</View>;
}

const styles = StyleSheet.create({
  hero: {
    paddingTop: authSpace[4],
    paddingBottom: 56,
    paddingHorizontal: 20,
    backgroundColor: authColors.canvas,
    overflow: "hidden",
  },
  heroDark: { backgroundColor: "#0B3AB4" },
  heroWithChildren: { paddingBottom: 48 },
  band: { position: "absolute", top: 0, left: 0, right: 0, opacity: 0.6 },
  edge: { position: "absolute", top: 0, height: "100%", width: 28, opacity: 0.3 },
  edgeDark: { opacity: 0.12 },
  edgeLeft: { left: 0 },
  edgeRight: { right: 0 },
  art: { position: "absolute", top: authSpace[2], right: 0 },
  script: { position: "absolute", right: 4, top: "6%" },
  scriptDark: { tintColor: colors.white, opacity: 0.9 },
  back: { width: 48, height: 48, alignItems: "center", justifyContent: "center", marginLeft: -authSpace[3] },
  pressed: { opacity: 0.7 },
  brand: { marginTop: authSpace[2] },
  brandAfterBack: { marginTop: -authSpace[3] },
  copyBlock: { marginTop: authSpace[3], gap: authSpace[1], alignItems: "center" },
  heading: { ...authType.h1, fontSize: 32, lineHeight: 38, color: authColors.navy950, textAlign: "center" },
  headingCompact: { fontSize: 26, lineHeight: 32 },
  subheading: { ...authType.body, fontSize: 16, lineHeight: 23, color: authColors.textSecondary, textAlign: "center" },
  onDark: { color: colors.white },
  subheadingDark: { color: "#DCE7FA" },
  children: { marginTop: authSpace[4] },
  card: {
    marginTop: -32,
    backgroundColor: authColors.white,
    borderTopLeftRadius: 36,
    borderTopRightRadius: 36,
    paddingHorizontal: 20,
    paddingTop: authSpace[4],
    paddingBottom: authSpace[5],
    gap: authSpace[3],
    shadowColor: colors.navy900,
    shadowOpacity: 0.12,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: -4 },
    elevation: 3,
  },
});
