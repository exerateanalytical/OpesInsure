import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { authColors, authSpace, colors, type } from "@/theme/tokens";
import { KenteBand } from "@/components/HeritagePattern";

/** Brand microcopy + kente strip at the foot of the auth screens. `tone`
 * matches the background it sits on so the tagline stays AA readable
 * (the old slate tagline was ~2.5:1 on the indigo sign-in background). */
export function AuthFooterBranding({ tone = "dark" }: { tone?: "dark" | "light" }) {
  return (
    <View style={styles.wrap}>
      <Text style={[styles.tagline, { color: tone === "dark" ? colors.gold100 : authColors.slate500 }]}>
        PEOPLE · PROTECTION · A BRIGHTER TOMORROW
      </Text>
      <KenteBand height={8} />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginTop: authSpace[4], gap: authSpace[2] },
  tagline: { ...type.eyebrow, textAlign: "center", paddingHorizontal: authSpace[4] },
});
