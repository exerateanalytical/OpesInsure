import React, { useState } from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { colors, radius, type } from "@/theme/tokens";

/**
 * Insurer / broker mark: the licensed logo from the API (logo_url) when it
 * exists and loads, otherwise the initials in an outlined box. One component
 * for the directory list, company profile, marketplace rows and offer cards.
 */
export function InstitutionMark({
  logoUrl,
  initials,
  size = 42,
}: {
  logoUrl?: string | null;
  initials?: string | null;
  size?: number;
}) {
  const [failed, setFailed] = useState(false);
  const url = typeof logoUrl === "string" && /^https:\/\//i.test(logoUrl) ? logoUrl : null;
  const box = { width: size, height: size, borderRadius: size >= 56 ? radius.card : radius.control };
  if (url && !failed) {
    return (
      <View style={[styles.box, styles.logoBox, box]}>
        <Image
          source={{ uri: url }}
          // Directory logos are 512px squares with generous margins: keep the inset small so they read.
          style={{ width: size - 4, height: size - 4 }}
          resizeMode="contain"
          accessibilityIgnoresInvertColors
          onError={() => setFailed(true)}
        />
      </View>
    );
  }
  return (
    <View style={[styles.box, box]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <Text style={[size >= 56 ? type.label : type.caption, styles.initials, size < 36 && { fontSize: 9, lineHeight: 12 }]} numberOfLines={1}>
        {(initials ?? "").slice(0, 3)}
      </Text>
    </View>
  );
}

/** logo_url from an institution row, tolerating the draft name `logo`. */
export const institutionLogo = (row: { logo_url?: string | null; logo?: string | null } | null | undefined) =>
  row?.logo_url ?? row?.logo ?? null;

const styles = StyleSheet.create({
  box: {
    borderWidth: 1,
    borderColor: colors.neutral200,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  logoBox: { borderColor: colors.neutral200 },
  initials: { color: colors.navy900 },
});
