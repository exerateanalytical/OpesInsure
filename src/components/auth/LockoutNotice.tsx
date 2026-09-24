import React, { useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { LockKeyhole } from "lucide-react-native";
import { useTranslation } from "@/i18n";
import { formatCountdown } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Dedicated "too many attempts / locked" state. Counts down the server's
 * Retry-After and calls onDone when the user may try again.
 */
export function LockoutNotice({ seconds, onDone }: { seconds: number; onDone: () => void }) {
  const { t } = useTranslation();
  const [until] = useState(() => Date.now() + seconds * 1000);
  const [left, setLeft] = useState(seconds);
  useEffect(() => {
    const timer = setInterval(() => {
      const remaining = Math.ceil((until - Date.now()) / 1000);
      setLeft(Math.max(0, remaining));
      if (remaining <= 0) {
        clearInterval(timer);
        onDone();
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [until, onDone]);
  return (
    <View style={styles.box} accessibilityRole="alert" accessibilityLiveRegion="polite">
      <LockKeyhole size={24} color={colors.dangerText} />
      <View style={styles.flex}>
        <Text style={styles.title}>{t("lockedTitle")}</Text>
        <Text style={styles.body}>{t("lockedBody", { time: formatCountdown(left) })}</Text>
        <Text style={styles.meta}>{t("lockedHint")}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    flexDirection: "row",
    gap: space.x3,
    padding: space.x4,
    borderRadius: radius.card,
    backgroundColor: colors.dangerSoft,
    borderWidth: 1,
    borderColor: colors.danger,
  },
  flex: { flex: 1, gap: space.x1 },
  title: { ...type.label, fontSize: 16, color: colors.dangerText },
  body: { ...type.body, color: colors.neutral800, fontVariant: ["tabular-nums"] },
  meta: { ...type.meta, color: colors.neutral700 },
});
