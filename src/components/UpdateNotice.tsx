import React, { useEffect, useRef, useState } from "react";
import { ActivityIndicator, AppState, Modal, Pressable, StyleSheet, Text, View } from "react-native";
import * as Updates from "expo-updates";
import { RefreshCw } from "lucide-react-native";
import { Button } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * expo-updates' native "checkAutomatically: ON_LOAD" is silent: it checks on
 * cold start, downloads in the background, and only shows the new JS on the
 * *next* cold start — so applying an update needs two full app kills with no
 * indication either happened. This checks explicitly (on launch and whenever
 * the app is foregrounded, not just on a cold start) and shows a centred
 * "Update ready" dialog the moment a new bundle is ready, with Restart now
 * and Later, instead of leaving it to a silent background swap.
 */
export function UpdateNotice() {
  const { t } = useTranslation();
  const [ready, setReady] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const [restarting, setRestarting] = useState(false);
  const busy = useRef(false);

  const check = async () => {
    if (busy.current || !Updates.isEnabled || __DEV__) return;
    busy.current = true;
    try {
      const result = await Updates.checkForUpdateAsync();
      if (result.isAvailable) {
        const fetched = await Updates.fetchUpdateAsync();
        if (!fetched.isRollBackToEmbedded && fetched.isNew) {
          setReady(true);
          // A newly fetched bundle re-opens the dialog even after "Later".
          setDismissed(false);
        }
      }
    } catch {
      // Never block the app over a failed update check (e.g. offline).
    } finally {
      busy.current = false;
    }
  };

  useEffect(() => {
    void check();
    const sub = AppState.addEventListener("change", (next) => {
      if (next === "active") void check();
    });
    return () => sub.remove();
  }, []);

  if (!ready) return null;

  const restart = () => {
    setRestarting(true);
    void Updates.reloadAsync().catch(() => setRestarting(false));
  };

  // After "Later", a small centred pill stays reachable at the top so the
  // update is never lost; tapping it restarts.
  if (dismissed) {
    return (
      <View style={styles.pillRow} pointerEvents="box-none">
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t("updateRestart")}
          disabled={restarting}
          onPress={restart}
          style={styles.pill}
        >
          {restarting ? <ActivityIndicator color={colors.white} size="small" /> : <RefreshCw size={14} color={colors.white} />}
          <Text style={styles.pillText}>{restarting ? t("updateRestarting") : t("updateReadyShort")}</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <Modal visible transparent animationType="fade" statusBarTranslucent onRequestClose={() => setDismissed(true)}>
      <View style={styles.backdrop}>
        <View accessibilityViewIsModal accessibilityRole="alert" style={styles.dialog}>
          <RefreshCw size={32} color={colors.navy800} />
          <Text accessibilityRole="header" style={styles.title}>{t("updateReadyTitle")}</Text>
          <Text style={styles.body}>{t("updateReadyBody")}</Text>
          <Button label={restarting ? t("updateRestarting") : t("updateRestart")} icon={RefreshCw} loading={restarting} onPress={restart} />
          <Button label={t("updateLater")} variant="tertiary" disabled={restarting} onPress={() => setDismissed(true)} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: "rgba(15,21,53,0.55)",
    alignItems: "center",
    justifyContent: "center",
    padding: space.x6,
  },
  dialog: {
    width: "100%",
    maxWidth: 400,
    backgroundColor: colors.white,
    borderRadius: radius.sheet,
    padding: space.x6,
    gap: space.x3,
    alignItems: "stretch",
  },
  title: { ...type.sectionTitle, color: colors.navy950, textAlign: "center" },
  body: { ...type.body, color: colors.neutral600, textAlign: "center", marginBottom: space.x2 },
  pillRow: { alignItems: "center", paddingTop: space.x1 },
  pill: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    backgroundColor: colors.blue700,
    borderRadius: radius.pill,
    paddingHorizontal: space.x4,
    minHeight: 32,
  },
  pillText: { ...type.caption, color: colors.white },
});
