import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Check, X } from "lucide-react-native";
import { claimRail, RAIL_STEPS } from "@/lib/claimStatus";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { colors, type } from "@/theme/tokens";

const LABELS: Record<(typeof RAIL_STEPS)[number], CopyKey> = {
  submitted: "railSubmitted",
  assessment: "railAssessment",
  decision: "railDecision",
  settlement: "railSettlement",
};

/**
 * Four-node horizontal progress rail on each claim card (design 29):
 * Submitted → Assessment → Decision → Settlement, with the date under done
 * nodes, "In Progress" under the current one and a red X when declined.
 */
export function ClaimRail({ status, dates = [] }: { status: string; dates?: (string | null | undefined)[] }) {
  const { t, date } = useTranslation();
  const states = claimRail(status);
  return (
    <View style={s.rail} accessibilityRole="progressbar" accessibilityLabel={t("trackTitle")}>
      {RAIL_STEPS.map((step, i) => {
        const state = states[i] ?? "upcoming";
        const last = i === RAIL_STEPS.length - 1;
        const prevDone = i > 0 && (states[i - 1] === "done" || states[i - 1] === "rejected");
        const caption =
          state === "rejected"
            ? dates[i] ? date(dates[i]) : null
            : state === "current"
              ? t("railInProgress")
              : state === "done"
                ? dates[i] ? date(dates[i]) : null
                : "-";
        return (
          <View key={step} style={s.col}>
            <View style={s.track}>
              <View style={[s.line, i === 0 && s.lineHidden, prevDone && s.lineOn]} />
              <View style={[s.dot, state === "done" && s.dotDone, state === "current" && s.dotCurrent, state === "rejected" && s.dotRejected]}>
                {state === "done" ? (
                  <Check size={13} color={colors.white} strokeWidth={3} />
                ) : state === "rejected" ? (
                  <X size={13} color={colors.white} strokeWidth={3} />
                ) : (
                  <Text style={[s.num, state === "current" && s.numOn]}>{i + 1}</Text>
                )}
              </View>
              <View style={[s.line, last && s.lineHidden, state === "done" && s.lineOn]} />
            </View>
            <Text style={[s.label, state === "upcoming" && s.muted, state === "rejected" && s.rejected]} numberOfLines={2} maxFontSizeMultiplier={1.3}>
              {state === "rejected" ? t("railRejected") : t(LABELS[step])}
            </Text>
            {caption ? <Text style={[s.caption, state === "current" && s.captionOn]} numberOfLines={2} maxFontSizeMultiplier={1.3}>{caption}</Text> : null}
          </View>
        );
      })}
    </View>
  );
}

const s = StyleSheet.create({
  rail: { flexDirection: "row", alignItems: "flex-start", marginTop: 4 },
  col: { flex: 1, alignItems: "center", gap: 3 },
  track: { flexDirection: "row", alignItems: "center", alignSelf: "stretch" },
  line: { flex: 1, height: 2, backgroundColor: colors.neutral200 },
  lineOn: { backgroundColor: colors.blue600 },
  lineHidden: { backgroundColor: "transparent" },
  dot: { width: 24, height: 24, borderRadius: 12, backgroundColor: colors.neutral100, borderWidth: 1.5, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  dotDone: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  dotCurrent: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  dotRejected: { backgroundColor: colors.danger, borderColor: colors.danger },
  num: { fontFamily: "Inter_700Bold", fontSize: 11, lineHeight: 14, color: colors.neutral500 },
  numOn: { color: colors.white },
  label: { ...type.caption, fontSize: 11, lineHeight: 14, color: colors.navy950, textAlign: "center" },
  muted: { color: colors.neutral500 },
  rejected: { color: colors.dangerText },
  caption: { ...type.caption, fontSize: 11, lineHeight: 14, fontFamily: "Inter_400Regular", color: colors.neutral500, textAlign: "center" },
  captionOn: { color: colors.blue600, fontFamily: "Inter_500Medium" },
});
