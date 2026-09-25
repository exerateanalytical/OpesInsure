import React, { useId } from "react";
import { StyleProp, StyleSheet, View, ViewStyle } from "react-native";
import Svg, { Circle, Defs, Path, Pattern, Rect } from "react-native-svg";
import { colors, heritage } from "@/theme/tokens";

/**
 * Vector heritage motifs (react-native-svg, already a dependency; no new
 * native code). Vector art scales to any width with no raster stretching,
 * which is what distorted the old PNG hero decorations.
 *
 * - "ndop": the indigo-and-white lattice of Cameroonian ndop cloth
 *   (diamonds, cross-hatching, dots). Use as a faint texture over a dark
 *   indigo hero.
 * - "kente": a woven strip of ochre / terracotta / indigo blocks and
 *   stripes. Use as a thin band under headers or at the foot of a screen.
 *
 * Decorative only: hidden from screen readers and never intercepts touches.
 */
export function HeritagePattern({
  variant,
  height,
  opacity = 1,
  color,
  style,
}: {
  variant: "ndop" | "kente";
  /** Omit to fill the parent (absolute fill). */
  height?: number;
  opacity?: number;
  /** Line colour for "ndop" (default white). */
  color?: string;
  style?: StyleProp<ViewStyle>;
}) {
  const id = `hp${useId().replace(/[^A-Za-z0-9]/g, "")}`;
  const fill = height === undefined;
  return (
    <View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={[fill ? StyleSheet.absoluteFill : { height, alignSelf: "stretch" }, { opacity }, style]}
    >
      <Svg width="100%" height="100%">
        <Defs>{variant === "ndop" ? <NdopTile id={id} color={color ?? heritage.ndopLine} /> : <KenteTile id={id} height={height ?? 12} />}</Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${id})`} />
      </Svg>
    </View>
  );
}

function NdopTile({ id, color }: { id: string; color: string }) {
  // 32x32 tile: a diamond, an inner cross-hatched diamond and corner dots.
  return (
    <Pattern id={id} patternUnits="userSpaceOnUse" width="32" height="32">
      <Path d="M16 1 L31 16 L16 31 L1 16 Z" stroke={color} strokeWidth="1.2" fill="none" />
      <Path d="M16 8 L24 16 L16 24 L8 16 Z" stroke={color} strokeWidth="1" fill="none" />
      <Path d="M12 12 L20 20 M20 12 L12 20" stroke={color} strokeWidth="0.8" />
      <Circle cx="0" cy="0" r="1.6" fill={color} />
      <Circle cx="32" cy="0" r="1.6" fill={color} />
      <Circle cx="0" cy="32" r="1.6" fill={color} />
      <Circle cx="32" cy="32" r="1.6" fill={color} />
    </Pattern>
  );
}

function KenteTile({ id, height }: { id: string; height: number }) {
  // 48-wide woven repeat: ochre block with indigo stripes, terracotta block
  // with a zig-zag, indigo block with gold weft lines.
  const [gold, terracotta, indigo, cream] = heritage.kente;
  const h = height;
  const zig = `M24 ${h * 0.75} L28 ${h * 0.25} L32 ${h * 0.75} L36 ${h * 0.25} L40 ${h * 0.75}`;
  return (
    <Pattern id={id} patternUnits="userSpaceOnUse" width="48" height={String(h)}>
      <Rect x="0" y="0" width="16" height={h} fill={gold} />
      <Rect x="4" y="0" width="2" height={h} fill={indigo} />
      <Rect x="10" y="0" width="2" height={h} fill={indigo} />
      <Rect x="16" y="0" width="4" height={h} fill={cream} />
      <Rect x="20" y="0" width="24" height={h} fill={terracotta} />
      <Path d={zig} stroke={cream} strokeWidth="1.4" fill="none" />
      <Rect x="44" y="0" width="4" height={h} fill={indigo} />
      <Rect x="44" y={h / 2 - 0.75} width="4" height="1.5" fill={gold} />
    </Pattern>
  );
}

/** A thin kente strip, the signature divider of the identity. */
export function KenteBand({ height = 6, style }: { height?: number; style?: StyleProp<ViewStyle> }) {
  return <HeritagePattern variant="kente" height={height} style={style} />;
}

/** Indigo surface with a faint ndop texture (heroes, headers). */
export function NdopSurface({
  children,
  style,
  intensity = 0.08,
}: {
  children?: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  intensity?: number;
}) {
  return (
    <View style={[{ backgroundColor: colors.navy950, overflow: "hidden" }, style]}>
      <HeritagePattern variant="ndop" opacity={intensity} />
      {children}
    </View>
  );
}
