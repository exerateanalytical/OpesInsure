import React, { useEffect, useRef, useState } from "react";
import { ActivityIndicator, AppState, Pressable, StyleSheet, Text } from "react-native";
import * as Updates from "expo-updates";
import { RefreshCw } from "lucide-react-native";
import { colors, space, type } from "@/theme/tokens";

/**
 * expo-updates' native "checkAutomatically: ON_LOAD" is silent: it checks on
 * cold start, downloads in the background, and only shows the new JS on the
 * *next* cold start — so applying an update needs two full app kills with no
 * indication either happened. This checks explicitly (on launch and whenever
 * the app is foregrounded, not just on a cold start) and surfaces a visible
 * "Restart to update" banner the moment a new bundle is ready, instead of
 * leaving it to a silent background swap the user has no way to see.
 */
export function UpdateNotice() {
  const [ready, setReady] = useState(false);
  const [restarting, setRestarting] = useState(false);
  const busy = useRef(false);

  const check = async () => {
    if (busy.current || !Updates.isEnabled || __DEV__) return;
    busy.current = true;
    try {
      const result = await Updates.checkForUpdateAsync();
      if (result.isAvailable) {
        const fetched = await Updates.fetchUpdateAsync();
        if (!fetched.isRollBackToEmbedded && fetched.isNew) setReady(true);
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

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel="Restart to install the latest update"
      disabled={restarting}
      onPress={restart}
      style={styles.banner}
    >
      {restarting ? (
        <ActivityIndicator color={colors.white} size="small" />
      ) : (
        <RefreshCw size={16} color={colors.white} />
      )}
      <Text style={styles.text}>
        {restarting ? "Restarting…" : "An update is ready · Tap to restart"}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banner: {
    backgroundColor: colors.blue700,
    paddingHorizontal: space.x4,
    paddingVertical: space.x2,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
  },
  text: { ...type.meta, color: colors.white, textAlign: "center" },
});
