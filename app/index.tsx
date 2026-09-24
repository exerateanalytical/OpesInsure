import React, { useEffect, useState } from "react";
import { Image, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router } from "expo-router";
import { RefreshCw } from "lucide-react-native";
import { Button } from "@/components/ui";
import { sessionHome, useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const logo = require("../assets/icon.png");
const map = require("../assets/auth/splash_map.png");
const arcs = require("../assets/auth/splash_network_arcs.png");
const wave = require("../assets/auth/splash_bottom_wave.png");
const tribalLeft = require("../assets/auth/splash_tribal_left.png");
const tribalRight = require("../assets/auth/splash_tribal_right.png");

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
  const art = Math.min(360, width - space.x8);
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
      <Image source={tribalLeft} style={styles.tribalLeft} resizeMode="contain" />
      <Image source={tribalRight} style={styles.tribalRight} resizeMode="contain" />
      <View style={styles.center}>
        <View style={[styles.art, { width: art, height: art }]}>
          <Image source={map} style={styles.fill} resizeMode="contain" />
          <Image source={arcs} style={styles.fill} resizeMode="contain" />
          <Image
            source={logo}
            style={styles.logo}
            resizeMode="contain"
            accessibilityIgnoresInvertColors
          />
        </View>
        <Text accessibilityRole="header" style={styles.wordmark}>
          Opes<Text style={styles.accent}>Insure</Text>
        </Text>
        <Text style={styles.tagline}>{t("splashTagline")}</Text>
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
      <View style={styles.bottom}>
        <View style={styles.goldRule} />
        <Image source={wave} style={styles.wave} resizeMode="cover" />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  center: { alignItems: "center", gap: space.x3, paddingHorizontal: space.x5 },
  art: { alignItems: "center", justifyContent: "center" },
  fill: { position: "absolute", width: "100%", height: "100%", opacity: 0.9 },
  logo: { width: 112, height: 112, borderRadius: 26 },
  wordmark: { ...type.pageTitle, color: colors.navy950 },
  accent: { color: colors.blue600 },
  tagline: { ...type.caption, color: colors.navy800, letterSpacing: 2, textAlign: "center" },
  offline: { alignSelf: "stretch", gap: space.x2, marginTop: space.x4, alignItems: "stretch" },
  offlineTitle: { ...type.cardTitle, color: colors.navy950, textAlign: "center" },
  offlineBody: { ...type.body, color: colors.neutral600, textAlign: "center" },
  tribalLeft: { position: "absolute", left: -30, top: 40, width: 90, height: 340, opacity: 0.35 },
  tribalRight: { position: "absolute", right: -30, bottom: 120, width: 90, height: 340, opacity: 0.35 },
  bottom: { position: "absolute", bottom: 0, left: 0, right: 0 },
  goldRule: { height: 3, backgroundColor: colors.gold500, marginHorizontal: space.x16, borderRadius: 2, marginBottom: space.x2 },
  wave: { width: "100%", height: 56 },
});
