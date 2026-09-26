import React from "react";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authSpace, authType, colors, type } from "@/theme/tokens";
import { useColumns } from "@/components/responsive";
import { KenteBand } from "@/components/HeritagePattern";

const icon = require("../../../assets/icon.png");
const mapNetwork = require("../../../assets/brand/map_network.png");
const scriptTagline = require("../../../assets/brand/script_tagline.png");
const edgeLeft = require("../../../assets/brand/edge_left.png");
const edgeRight = require("../../../assets/brand/edge_right.png");
const footerWave = require("../../../assets/brand/footer_wave.png");

/**
 * Light design-system canvas behind the onboarding pager: faint blue
 * geometric borders down both edges and the blue/gold wave along the
 * bottom. Purely decorative (hidden from screen readers, no touches).
 * The images keep their aspect ratio (resizeMode contain / cover on a
 * fixed-ratio box), so nothing stretches on tablets or foldables.
 */
export function OnboardingCanvas({ waveHeight = 150 }: { waveHeight?: number }) {
  const { width } = useWindowDimensions();
  // The strips are ~1:11 tall; "cover" fills the full height and crops the
  // middle of the strip, so the motif stays centred at any screen height.
  const edgeWidth = Math.max(24, Math.min(40, width * 0.09));
  return (
    <View
      style={StyleSheet.absoluteFill}
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
    >
      <Image source={edgeLeft} style={[styles.edge, styles.edgeLeft, { width: edgeWidth }]} resizeMode="cover" />
      <Image source={edgeRight} style={[styles.edge, styles.edgeRight, { width: edgeWidth }]} resizeMode="cover" />
      <Image source={footerWave} style={[styles.wave, { height: waveHeight }]} resizeMode="cover" />
    </View>
  );
}

/**
 * Brand block of the light onboarding shell: the dotted-Africa network art
 * and the "A Safer Brighter Africa" script at the top right, the app icon
 * centred, then the two-tone wordmark and the eyebrow. The icon keeps a
 * square box and shrinks on 360dp phones or with large system fonts so the
 * slide copy and the actions stay on screen.
 */
export function OnboardingHero({ compact }: { compact?: boolean }) {
  const { width, fontScale } = useWindowDimensions();
  const small = compact || width < 360 || fontScale > 1.2;
  const iconSize = small ? 88 : 116;
  const artSize = Math.min(small ? 200 : 250, width * 0.62);
  return (
    <View style={[styles.heroWrap, small && styles.heroWrapSmall]}>
      <View
        style={[styles.heroArt, { width: artSize, height: artSize }]}
        pointerEvents="none"
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
      >
        <Image source={mapNetwork} style={styles.heroMap} resizeMode="contain" />
        <Image source={scriptTagline} style={styles.heroScript} resizeMode="contain" />
      </View>
      <View style={styles.brandBlock}>
        <Image
          source={icon}
          style={[styles.icon, { width: iconSize, height: iconSize, borderRadius: iconSize * 0.24 }]}
          resizeMode="contain"
          accessibilityIgnoresInvertColors
        />
        <Text style={[styles.wordmark, small && styles.wordmarkSmall]} numberOfLines={1} adjustsFontSizeToFit>
          Opes<Text style={styles.wordmarkAccent}>Insure</Text>
        </Text>
        <Text style={styles.tagline} numberOfLines={1} adjustsFontSizeToFit>
          INSURANCE FOR A BRIGHTER TOMORROW
        </Text>
      </View>
    </View>
  );
}

export type FeatureItem = { icon: LucideIcon; label: string; caption?: string };

/** The 3-column row of round light-blue badges used on slides 1 and 2. */
export function OnboardingFeatureRow({ items }: { items: FeatureItem[] }) {
  return (
    <View style={styles.row}>
      {items.map((item, index) => {
        const Icon = item.icon;
        return (
          <React.Fragment key={item.label}>
            {index > 0 ? <View style={styles.rowDivider} /> : null}
            <View style={styles.rowItem}>
              <View style={styles.rowBadge}>
                <Icon size={authIcon.feature + 8} strokeWidth={authIcon.strokeWidth} color={authColors.navy800} />
              </View>
              <Text style={styles.rowLabel}>{item.label}</Text>
              {item.caption ? <Text style={styles.rowCaption}>{item.caption}</Text> : null}
            </View>
          </React.Fragment>
        );
      })}
    </View>
  );
}

/** The 2x2 audience-node grid used on slide 3: solid blue round badges with a
 * gold ring, around the dotted-Africa network art. */
export function OnboardingNodeGrid({ items }: { items: FeatureItem[] }) {
  // 2x2 on phones; a single column when a cell would be under 150dp.
  const grid = useColumns({ max: 2, minItem: 150, gap: authSpace[2] });
  return (
    <View style={styles.nodeWrap}>
      <Image
        source={mapNetwork}
        style={styles.nodeMap}
        resizeMode="contain"
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
      />
      <View style={[styles.nodeGrid, grid.row]}>
        {items.map((item) => {
          const Icon = item.icon;
          return (
            <View key={item.label} style={[styles.nodeItem, grid.item]}>
              <View style={styles.nodeBadgeRing}>
                <View style={styles.nodeBadge}>
                  <Icon size={authIcon.feature + 6} strokeWidth={authIcon.strokeWidth} color={colors.white} />
                </View>
              </View>
              <Text style={styles.rowLabel}>{item.label}</Text>
              {item.caption ? <Text style={styles.nodeCaption}>{item.caption}</Text> : null}
            </View>
          );
        })}
      </View>
    </View>
  );
}

/** Footer eyebrow ("PEOPLE • PROTECTION • A BRIGHTER TOMORROW") over a short
 * gold rule; the rule is the woven kente strip so the heritage vector stays
 * on the screen without a stretched raster. */
export function OnboardingFooter({ tagline }: { tagline: string }) {
  return (
    <View style={styles.footerWrap}>
      <Text style={styles.footerTagline}>{tagline}</Text>
      <View style={styles.footerRule} />
      <KenteBand height={2} style={styles.footerBand} />
    </View>
  );
}

export function PaginationDots({ count, active }: { count: number; active: number }) {
  return (
    <View style={styles.dots}>
      {Array.from({ length: count }).map((_, index) => (
        <View key={index} style={[styles.dot, index === active && styles.dotActive]} />
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  // Explicit height: RN-web sizes an Image from its bitmap when only top/bottom are set.
  edge: { position: "absolute", top: 0, height: "100%", opacity: 0.32 },
  edgeLeft: { left: 0 },
  edgeRight: { right: 0 },
  wave: { position: "absolute", left: 0, right: 0, bottom: 0, width: "100%" },

  heroWrap: { paddingTop: authSpace[6], alignItems: "center", minHeight: 250 },
  heroWrapSmall: { paddingTop: authSpace[4], minHeight: 200 },
  heroArt: { position: "absolute", top: 0, right: -authSpace[4] },
  heroMap: { position: "absolute", left: 0, top: 0, width: "78%", height: "100%", opacity: 0.95 },
  heroScript: { position: "absolute", right: 0, top: "14%", width: "34%", height: "34%" },
  brandBlock: { alignItems: "center", gap: authSpace[1], paddingHorizontal: authSpace[3] },
  icon: {
    shadowColor: colors.navy900,
    shadowOpacity: 0.25,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
    elevation: 8,
  },
  wordmark: { ...authType.h1, fontSize: 38, lineHeight: 46, color: authColors.navy950, marginTop: authSpace[2] },
  wordmarkSmall: { fontSize: 32, lineHeight: 38 },
  wordmarkAccent: { color: authColors.azure500 },
  tagline: { ...type.eyebrow, fontSize: 10.5, color: authColors.navy900, letterSpacing: 2.2, textAlign: "center", paddingHorizontal: authSpace[2] },

  row: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center", paddingHorizontal: authSpace[1] },
  rowDivider: { width: 1, backgroundColor: authColors.ice100, marginTop: 24, height: 44 },
  rowItem: { flex: 1, alignItems: "center", gap: authSpace[1], paddingHorizontal: authSpace[1] },
  rowBadge: {
    width: 84,
    height: 84,
    borderRadius: 42,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: authColors.ice50,
    borderWidth: 1,
    borderColor: authColors.ice100,
    marginBottom: authSpace[1],
    shadowColor: colors.blue600,
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 3,
  },
  rowLabel: { ...authType.label, fontSize: 15, color: authColors.navy950, textAlign: "center" },
  rowCaption: { ...authType.body, fontSize: 13, lineHeight: 18, color: authColors.textSecondary, textAlign: "center" },

  nodeWrap: { alignItems: "center", justifyContent: "center", minHeight: 300 },
  nodeMap: { position: "absolute", width: 230, height: 230, opacity: 0.85 },
  nodeGrid: {
    width: "100%",
    justifyContent: "center",
  },
  nodeItem: { alignItems: "center", gap: 4, marginBottom: authSpace[4] },
  nodeBadgeRing: {
    width: 88,
    height: 88,
    borderRadius: 44,
    borderWidth: 2,
    borderColor: authColors.gold500,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    marginBottom: authSpace[1],
    shadowColor: colors.blue600,
    shadowOpacity: 0.25,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 4,
  },
  nodeBadge: {
    width: 76,
    height: 76,
    borderRadius: 38,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: authColors.navy900,
  },
  nodeCaption: { ...authType.label, fontSize: 10, color: authColors.slate500, letterSpacing: 1.4 },

  footerWrap: { marginTop: authSpace[3], alignItems: "center", gap: authSpace[2] },
  footerTagline: {
    ...type.eyebrow,
    color: authColors.navy900,
    textAlign: "center",
    letterSpacing: 2.2,
  },
  footerRule: { width: 96, height: 3, borderRadius: 2, backgroundColor: authColors.gold500 },
  footerBand: { width: 96, borderRadius: 1, overflow: "hidden", opacity: 0.35, marginTop: -authSpace[1] },

  // Lives in the welcome action bar (left of Next), so no outer margin.
  dots: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 48 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: authColors.ice100 },
  dotActive: { backgroundColor: authColors.blue500, width: 22 },
});
