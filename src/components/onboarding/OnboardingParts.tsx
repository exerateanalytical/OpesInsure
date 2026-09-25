import React from "react";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authSpace, authType, colors, type } from "@/theme/tokens";
import { useColumns } from "@/components/responsive";
import { KenteBand, NdopSurface } from "@/components/HeritagePattern";

const icon = require("../../../assets/icon.png");
const map = require("../../../assets/auth/splash_map.png");

/**
 * Brand block over an indigo ndop panel with a kente strip. The decoration is
 * vector (HeritagePattern), so nothing stretches on 360dp phones, tablets or
 * foldables; the icon keeps a square box and scales with the viewport.
 */
export function OnboardingHero({ compact }: { compact?: boolean }) {
  const { width, fontScale } = useWindowDimensions();
  // Shorter hero on small phones and with large system fonts, so the slide
  // copy and the actions stay on screen.
  const small = compact || width < 360 || fontScale > 1.2;
  const iconSize = small ? 64 : 84;
  return (
    <View style={styles.heroWrap}>
      <NdopSurface style={[styles.heroPanel, small && styles.heroPanelSmall]} intensity={0.1}>
        <KenteBand height={6} />
        <View style={styles.brandBlock}>
          <Image
            source={icon}
            style={{ width: iconSize, height: iconSize, borderRadius: iconSize * 0.24 }}
            resizeMode="contain"
            accessibilityIgnoresInvertColors
          />
          <Text style={styles.wordmark} numberOfLines={1} adjustsFontSizeToFit>
            Opes<Text style={styles.wordmarkAccent}>Insure</Text>
          </Text>
          <Text style={styles.tagline}>INSURANCE FOR A BRIGHTER TOMORROW</Text>
        </View>
      </NdopSurface>
    </View>
  );
}

export type FeatureItem = { icon: LucideIcon; label: string; caption?: string };

/** The 3-column icon row used on slides 1 and 2. */
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
                <Icon size={authIcon.feature} strokeWidth={authIcon.strokeWidth} color={authColors.navy800} />
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

/** The 2x2 audience-node grid used on slide 3, with the map/network art behind it. */
export function OnboardingNodeGrid({ items }: { items: FeatureItem[] }) {
  // 2x2 on phones; a single column when a cell would be under 150dp.
  const grid = useColumns({ max: 2, minItem: 150, gap: authSpace[2] });
  return (
    <View style={styles.nodeWrap}>
      <Image source={map} style={styles.nodeMap} resizeMode="contain" />
      <View style={[styles.nodeGrid, grid.row]}>
        {items.map((item) => {
          const Icon = item.icon;
          return (
            <View key={item.label} style={[styles.nodeItem, grid.item]}>
              <View style={styles.nodeBadge}>
                <Icon size={authIcon.feature} strokeWidth={authIcon.strokeWidth} color={authColors.white} />
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

export function OnboardingFooter({ tagline }: { tagline: string }) {
  return (
    <View style={styles.footerWrap}>
      <Text style={styles.footerTagline}>{tagline}</Text>
      <KenteBand height={6} style={styles.footerBand} />
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
  heroWrap: { paddingTop: authSpace[2] },
  heroPanel: { borderRadius: 24, paddingBottom: authSpace[5] },
  heroPanelSmall: { paddingBottom: authSpace[3] },
  brandBlock: { alignItems: "center", gap: authSpace[1], paddingTop: authSpace[4], paddingHorizontal: authSpace[3] },
  wordmark: { ...authType.h2, color: authColors.white, marginTop: authSpace[1] },
  wordmarkAccent: { color: authColors.azure500 },
  tagline: { ...type.eyebrow, color: colors.gold100, textAlign: "center" },

  row: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center", paddingHorizontal: authSpace[2] },
  rowDivider: { width: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: 12, height: 60 },
  rowItem: { flex: 1, alignItems: "center", gap: authSpace[1], paddingHorizontal: authSpace[1] },
  rowBadge: {
    width: 56,
    height: 56,
    borderRadius: 16,
    backgroundColor: colors.gold50,
    borderWidth: 1,
    borderColor: colors.gold100,
    alignItems: "center",
    justifyContent: "center",
  },
  rowLabel: { ...authType.label, fontSize: 13, color: authColors.navy950, textAlign: "center" },
  rowCaption: { ...authType.body, fontSize: 12, color: authColors.textSecondary, textAlign: "center" },

  nodeWrap: { alignItems: "center", justifyContent: "center", minHeight: 300 },
  nodeMap: { position: "absolute", width: 170, height: 170, opacity: 0.8 },
  nodeGrid: {
    width: "100%",
    justifyContent: "center",
  },
  nodeItem: { alignItems: "center", gap: 4, marginBottom: authSpace[4] },
  nodeBadge: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: authColors.navy800,
    borderWidth: 2,
    borderColor: colors.gold500,
    alignItems: "center",
    justifyContent: "center",
  },
  nodeCaption: { ...authType.label, fontSize: 10, color: authColors.slate500, letterSpacing: 1 },

  footerWrap: { marginTop: authSpace[3] },
  footerTagline: {
    ...authType.label,
    color: authColors.slate500,
    textAlign: "center",
    letterSpacing: 1.5,
    fontSize: 11,
    marginBottom: authSpace[2],
  },
  footerBand: { borderRadius: 3, overflow: "hidden" },

  dots: { flexDirection: "row", justifyContent: "center", gap: 6, marginVertical: authSpace[3] },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: authColors.ice200 },
  dotActive: { backgroundColor: colors.terracotta500, width: 22 },
});
