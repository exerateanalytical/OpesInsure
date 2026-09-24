import React from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { authColors, authSpace, authType } from "@/theme/tokens";

const bottomWave = require("../../../assets/auth/bottom_wave_dark.png");

/** The gold/blue wave sweep + brand microcopy at the very foot of the screen. */
export function AuthFooterBranding() {
  return (
    <View style={styles.wrap}>
      <Text style={styles.tagline}>PEOPLE · PROTECTION · A BRIGHTER TOMORROW</Text>
      <Image source={bottomWave} style={styles.wave} resizeMode="cover" />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginTop: authSpace[3] },
  tagline: {
    ...authType.label,
    color: authColors.slate500,
    textAlign: "center",
    letterSpacing: 1.5,
    fontSize: 11,
    marginBottom: authSpace[2],
  },
  wave: { width: "100%", height: 46 },
});
