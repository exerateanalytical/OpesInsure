import React, { useEffect, useState } from "react";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router } from "expo-router";
import { RefreshCw } from "lucide-react-native";
import { Button } from "@/components/ui";
import { EntryLockup } from "@/components/BrandMark";
import { sessionHome, useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const mapNetwork = require("../assets/brand/map_network.png");
const scriptTagline = require("../assets/brand/script_tagline.png");
const wave = require("../assets/brand/footer_wave.png");
const edgeLeft = require("../assets/brand/edge_left.png");
const edgeRight = require("../assets/brand/edge_right.png");

/** White heritage splash: logo over the dotted-Africa network and gold arcs.
 * Matches the native splash (white background, same logo) so the hand-off
 * from the OS splash does not flash. */
export default function Splash() {
  const status = useSession((s) => s.status);
  const bootstrap = useSession((s) => s.bootstrap);
  const workspace = useSession((s) => s.activeWorkspace);
  const hydrate = useSession((s) => s.hydrate);
  const { t } = useTranslation();
  const { width } = useWindowDimensions();
  const iconSize = width < 380 ? 120 : 144;
  // Top-right art stops 8dp short of the centred icon.
  const art = Math.max(0, Math.min(240, width / 2 - iconSize / 2 - 8));
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    if (status === "booting" || status === "error") return;
    let live = true;
    const timer = setTimeout(async () => {
      const home = sessionHome({ status, bootstrap, activeWorkspace: workspace });
      if (home) {
        router.replace(home);
        return;
      }
      // Returning signed-out users skip the marketing slides.
      const seen = await Preferences.onboardingSeen();
      if (live) router.replace(seen ? "/(auth)/sign-in" : "/welcome");
    }, 700);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [status, bootstrap, workspace]);

  return (
    <SafeAreaView style={styles.page} edges={["top", "bottom"]}>
      <View style={StyleSheet.absoluteFill} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Image source={edgeLeft} style={[styles.edge, styles.edgeLeft]} resizeMode="cover" />
        <Image source={edgeRight} style={[styles.edge, styles.edgeRight]} resizeMode="cover" />
        {art >= 80 ? (
          <View style={[styles.art, { width: art, height: art }]}>
            <Image source={mapNetwork} style={{ width: art * 0.8, height: art * 0.8 }} resizeMode="contain" />
            <Image source={scriptTagline} style={[styles.script, { width: art * 0.36, height: art * 0.36 }]} resizeMode="contain" />
          </View>
        ) : null}
      </View>
      <View style={styles.center}>
        <Text accessibilityRole="header" style={styles.srOnly}>OpesInsure</Text>
        <EntryLockup iconSize={iconSize} tagline={t("splashTagline")} />
        {status === "error" ? (
          <View style={styles.offline}>
            <Text accessibilityRole="alert" style={styles.offlineTitle}>
              {t("splashOfflineTitle")}
            </Text>
            <Text style={styles.offlineBody}>{t("splashOfflineBody")}</Text>
            <Button
              label={t("retry")}
              icon={RefreshCw}
              loading={retrying}
              onPress={async () => {
                setRetrying(true);
                await hydrate();
                setRetrying(false);
              }}
            />
          </View>
        ) : null}
      </View>
      <View style={styles.bottom} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Text style={styles.footer}>{t("welcomeFooter")}</Text>
        <View style={styles.goldRule} />
        <Image source={wave} style={styles.wave} resizeMode="cover" />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  center: { alignItems: "center", gap: space.x3, paddingHorizontal: space.x5 },
  art: { position: "absolute", top: space.x8, right: 0 },
  script: { position: "absolute", right: 4, top: 0 },
  srOnly: { position: "absolute", width: 1, height: 1, opacity: 0 },
  footer: { ...type.eyebrow, color: colors.navy900, letterSpacing: 2, textAlign: "center", marginBottom: space.x2 },
  offline: { alignSelf: "stretch", gap: space.x2, marginTop: space.x4, alignItems: "stretch" },
  offlineTitle: { ...type.cardTitle, color: colors.navy950, textAlign: "center" },
  offlineBody: { ...type.body, color: colors.neutral600, textAlign: "center" },
  edge: { position: "absolute", top: 0, height: "100%", width: 32, opacity: 0.32 },
  edgeLeft: { left: 0 },
  edgeRight: { right: 0 },
  bottom: { position: "absolute", bottom: 0, left: 0, right: 0 },
  goldRule: { height: 3, width: 96, alignSelf: "center", backgroundColor: colors.gold500, borderRadius: 2, marginBottom: space.x4 },
  wave: { width: "100%", height: 140 },
});
