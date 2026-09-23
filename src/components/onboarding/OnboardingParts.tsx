import React from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authSpace, authType } from "@/theme/authTokens";

const icon = require("../../../assets/icon.png");
const map = require("../../../assets/auth/splash_map.png");
const arcs = require("../../../assets/auth/splash_network_arcs.png");
const safer = require("../../../assets/auth/splash_safer_brighter_africa.png");
const tribalLeft = require("../../../assets/auth/splash_tribal_left.png");
const tribalRight = require("../../../assets/auth/splash_tribal_right.png");
const bottomWave = require("../../../assets/auth/splash_bottom_wave.png");

/** Icon + wordmark + tagline + dotted-Africa/network accent, on a white/ice background. */
export function OnboardingHero() {
  return (
    <View style={styles.heroWrap}>
      <Image source={tribalLeft} style={styles.tribalLeft} resizeMode="contain" />
      <Image source={tribalRight} style={styles.tribalRight} resizeMode="contain" />
      <Image source={map} style={styles.map} resizeMode="contain" />
      <Image source={arcs} style={styles.map} resizeMode="contain" />
      <Image source={safer} style={styles.safer} resizeMode="contain" />
      <View style={styles.brandBlock}>
        <Image source={icon} style={styles.icon} resizeMode="contain" />
        <Text style={styles.wordmark}>
          Opes<Text style={styles.wordmarkAccent}>Insure</Text>
        </Text>
        <Text style={styles.tagline}>INSURANCE FOR A BRIGHTER TOMORROW</Text>
      </View>
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
  return (
    <View style={styles.nodeWrap}>
      <Image source={map} style={styles.nodeMap} resizeMode="contain" />
      <View style={styles.nodeGrid}>
        {items.map((item) => {
          const Icon = item.icon;
          return (
            <View key={item.label} style={styles.nodeItem}>
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
      <Image source={bottomWave} style={styles.footerWave} resizeMode="cover" />
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
  heroWrap: { alignItems: "center", paddingTop: authSpace[5], overflow: "hidden" },
  tribalLeft: { position: "absolute", left: -30, top: 0, width: 80, height: 320, opacity: 0.5 },
  tribalRight: { position: "absolute", right: -30, top: 60, width: 80, height: 320, opacity: 0.5 },
  map: { position: "absolute", right: 0, top: 0, width: 160, height: 160, opacity: 0.9 },
  safer: { position: "absolute", right: 4, top: -4, width: 78, height: 78 },
  brandBlock: { alignItems: "center", gap: authSpace[2] },
  icon: { width: 108, height: 108, borderRadius: 26 },
  wordmark: { ...authType.h2, color: authColors.navy950, marginTop: authSpace[1] },
  wordmarkAccent: { color: authColors.blue500 },
  tagline: { ...authType.label, color: authColors.navy800, letterSpacing: 2, fontSize: 11 },

  row: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center", paddingHorizontal: authSpace[2] },
  rowDivider: { width: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: 12, height: 60 },
  rowItem: { flex: 1, alignItems: "center", gap: authSpace[1], paddingHorizontal: authSpace[1] },
  rowBadge: {
    width: 56,
    height: 56,
    borderRadius: 16,
    backgroundColor: authColors.ice100,
    alignItems: "center",
    justifyContent: "center",
  },
  rowLabel: { ...authType.label, fontSize: 13, color: authColors.navy950, textAlign: "center" },
  rowCaption: { ...authType.body, fontSize: 12, color: authColors.textSecondary, textAlign: "center" },

  nodeWrap: { alignItems: "center", justifyContent: "center", minHeight: 300 },
  nodeMap: { position: "absolute", width: 170, height: 170, opacity: 0.8 },
  nodeGrid: {
    width: "100%",
    flexDirection: "row",
    flexWrap: "wrap",
    justifyContent: "space-between",
  },
  nodeItem: { width: "48%", alignItems: "center", gap: 4, marginBottom: authSpace[4] },
  nodeBadge: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: authColors.navy800,
    borderWidth: 2,
    borderColor: authColors.gold300,
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
  footerWave: { width: "100%", height: 46 },

  dots: { flexDirection: "row", justifyContent: "center", gap: 6, marginVertical: authSpace[3] },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: authColors.ice200 },
  dotActive: { backgroundColor: authColors.gold500, width: 22 },
});
