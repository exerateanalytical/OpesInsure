import React, { useEffect } from "react";
import { StyleSheet, Text, View } from "react-native";
import { LinearGradient } from "expo-linear-gradient";
import { router } from "expo-router";
import { BrandMark } from "@/components/BrandMark";
import { colors, space, type } from "@/theme/tokens";
import { sessionHome, useSession } from "@/store/session";

export default function SplashOne() {
  const status = useSession((s) => s.status);
  const bootstrap = useSession((s) => s.bootstrap);
  const workspace = useSession((s) => s.activeWorkspace);
  useEffect(() => {
    if (status === "booting") return;
    const timer = setTimeout(() => {
      // A token-holding (authenticated) user never lands on welcome: they go
      // to their portal, or to the role picker when several workspaces exist
      // and none (or a stale one) is stored.
      const home = sessionHome({ status, bootstrap, activeWorkspace: workspace });
      router.replace(home ?? "/welcome");
    }, 900);
    return () => clearTimeout(timer);
  }, [status, bootstrap, workspace]);
  return (
    <LinearGradient
      colors={[colors.navy950, colors.navy900]}
      style={styles.page}
    >
      <View style={styles.center}>
        <BrandMark inverse />
        <Text style={styles.promise}>Insurance made clear.</Text>
      </View>
      <View style={styles.motif}>
        <View style={[styles.line, { backgroundColor: colors.success }]} />
        <View style={[styles.line, { backgroundColor: colors.danger }]} />
        <View style={[styles.line, { backgroundColor: colors.gold500 }]} />
      </View>
    </LinearGradient>
  );
}
const styles = StyleSheet.create({
  page: { flex: 1, alignItems: "center", justifyContent: "center" },
  center: { alignItems: "center", gap: space.x4 },
  promise: { ...type.bodyLarge, color: colors.neutral200 },
  motif: {
    position: "absolute",
    bottom: 0,
    left: 0,
    right: 0,
    flexDirection: "row",
  },
  line: { flex: 1, height: 4 },
});
