import React, { useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { Download, X } from "lucide-react-native";
import { useRuntime } from "@/store/runtime";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Soft "new version available" pill for a NEW NATIVE BUILD (APK), driven by
 * the server's release.update_recommended / latest_version. Over-the-air
 * bundles are handled by UpdateNotice; this is for changes that need a new
 * install. Dismissible for the session; the forced gate (minimum_version)
 * still lives in RuntimeGate.
 */
export function NewVersionNotice() {
  const { t } = useTranslation();
  const release = useRuntime((s) => s.bootstrap?.release);
  const [dismissed, setDismissed] = useState(false);
  if (!release?.update_recommended || dismissed) return null;
  const url = release.store_url;
  return (
    <View style={styles.row} pointerEvents="box-none">
      <View style={styles.pill}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t("newVersionAvailable", { version: release.latest_version ?? "" })}
          onPress={() => (url ? void Linking.openURL(url).catch(() => undefined) : undefined)}
          style={styles.pillMain}
        >
          <Download size={14} color={colors.white} />
          <Text style={styles.text}>
            {t("newVersionAvailable", { version: release.latest_version ?? "" })}
          </Text>
        </Pressable>
        <Pressable accessibilityRole="button" accessibilityLabel={t("close")} hitSlop={8} onPress={() => setDismissed(true)}>
          <X size={14} color={colors.white} />
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { alignItems: "center", paddingTop: space.x1 },
  pill: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    backgroundColor: colors.navy900,
    borderRadius: radius.pill,
    paddingHorizontal: space.x4,
    minHeight: 32,
    maxWidth: "94%",
  },
  pillMain: { flexDirection: "row", alignItems: "center", gap: space.x2, flexShrink: 1 },
  text: { ...type.caption, color: colors.white, flexShrink: 1 },
});
