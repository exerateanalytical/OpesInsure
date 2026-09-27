/**
 * Decorative brand artwork from `app screens/` (optimised copies in assets/brand).
 * Always hidden from screen readers and non-interactive; height follows the
 * bitmap aspect ratio so art is never stretched.
 */
import React from "react";
import { Image, StyleProp, View, ViewStyle } from "react-native";

const ART = {
  map_gold_network: { src: require("../../../assets/brand/map_gold_network.png"), ratio: 0.988 },
  border_band: { src: require("../../../assets/brand/border_band.png"), ratio: 5.414 },
  corner_ornament: { src: require("../../../assets/brand/corner_ornament.png"), ratio: 0.816 },
  tribal_divider: { src: require("../../../assets/brand/tribal_divider.png"), ratio: 7.826 },
  cameroon_pin: { src: require("../../../assets/brand/cameroon_pin.png"), ratio: 0.713 },
  africa_dots_gold: { src: require("../../../assets/brand/africa_dots_gold.png"), ratio: 0.926 },
  africa_dots_blue: { src: require("../../../assets/brand/africa_dots_blue.png"), ratio: 1.048 },
  wave_ribbon_blue: { src: require("../../../assets/brand/wave_ribbon_blue.png"), ratio: 5.902 },
  tribal_border_gold: { src: require("../../../assets/brand/tribal_border_gold.png"), ratio: 18.462 },
  mist_wave: { src: require("../../../assets/brand/mist_wave.png"), ratio: 1.500 },
  glass_shield: { src: require("../../../assets/brand/glass_shield.png"), ratio: 0.914 },
  umbrella_icon: { src: require("../../../assets/brand/umbrella_icon.png"), ratio: 0.992 },
  light_node: { src: require("../../../assets/brand/light_node.png"), ratio: 1.019 },
  wave_lines_icy: { src: require("../../../assets/brand/wave_lines_icy.png"), ratio: 5.538 },
  network_arcs: { src: require("../../../assets/brand/network_arcs.png"), ratio: 1.087 },
  wave_ribbons_lux: { src: require("../../../assets/brand/wave_ribbons_lux.png"), ratio: 3.243 },
  gold_swoosh: { src: require("../../../assets/brand/gold_swoosh.png"), ratio: 8.462 },
  map_neon: { src: require("../../../assets/brand/map_neon.png"), ratio: 0.930 },
  logo_africa_lockup: { src: require("../../../assets/brand/logo_africa_lockup.png"), ratio: 4.444 },
  logo_protection_together: { src: require("../../../assets/brand/logo_protection_together.png"), ratio: 1.148 },
  tribal_wallpaper: { src: require("../../../assets/brand/tribal_wallpaper.png"), ratio: 1.000 },
  logo_wide: { src: require("../../../assets/brand/logo_wide.png"), ratio: 3.960 },
  logo_wide_alt: { src: require("../../../assets/brand/logo_wide_alt.png"), ratio: 4.167 },
} as const;

export type BrandArtName = keyof typeof ART;

export function BrandArt({
  name,
  width,
  opacity = 1,
  style,
}: {
  name: BrandArtName;
  width: number;
  opacity?: number;
  style?: StyleProp<ViewStyle>;
}) {
  const art = ART[name];
  return (
    <View
      pointerEvents="none"
      accessible={false}
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      aria-hidden
      style={[{ alignSelf: "center", maxWidth: "100%" }, style]}
    >
      <Image
        source={art.src}
        resizeMode="contain"
        style={{ width, maxWidth: "100%", height: Math.round(width / art.ratio), opacity }}
      />
    </View>
  );
}
