import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { AlertCircle, Check, Circle } from "lucide-react-native";
import { claimTracker, TRACKER_STEPS } from "@/lib/claimStatus";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { colors, space, type } from "@/theme/tokens";

const LABELS: Record<(typeof TRACKER_STEPS)[number], CopyKey> = {
  submitted: "trackSubmitted",
  documents: "trackDocuments",
  review: "trackReview",
  assessment: "trackAssessment",
  info: "trackInfo",
  decision: "trackDecision",
  settlement: "trackSettlement",
};

/** Fixed seven-step progress tracker, derived from the backend status. The
 * state is conveyed by icon and text, never by colour alone. */
export function ClaimTracker({ status }: { status: string }) {
  const { t } = useTranslation();
  const states = claimTracker(status);
  return (
    <View accessibilityRole="list" accessibilityLabel={t("trackTitle")}>
      {TRACKER_STEPS.map((step, i) => {
        const state = states[i] ?? "upcoming";
        const last = i === TRACKER_STEPS.length - 1;
        const stateLabel = t(
          state === "done" ? "stepDone" : state === "current" ? "stepCurrent" : state === "attention" ? "stepAttention" : "stepUpcoming",
        );
        return (
          <View key={step} style={styles.step} accessible accessibilityLabel={`${t(LABELS[step])}: ${stateLabel}`}>
            <View style={styles.rail}>
              <View
                style={[
                  styles.dot,
                  state === "done" && styles.dotDone,
                  state === "current" && styles.dotCurrent,
                  state === "attention" && styles.dotAttention,
                ]}
              >
                {state === "done" ? (
                  <Check size={14} color={colors.white} strokeWidth={3} />
                ) : state === "attention" ? (
                  <AlertCircle size={14} color={colors.white} />
                ) : (
                  <Circle size={8} color={state === "current" ? colors.white : colors.neutral400} fill={state === "current" ? colors.white : colors.neutral400} />
                )}
              </View>
              {!last ? <View style={[styles.line, state === "done" && styles.lineDone]} /> : null}
            </View>
            <View style={styles.copy}>
              <Text style={[styles.label, state === "upcoming" && styles.muted]}>{t(LABELS[step])}</Text>
              {state !== "upcoming" && state !== "done" ? <Text style={[styles.state, state === "attention" && styles.attention]}>{stateLabel}</Text> : null}
            </View>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  step: { flexDirection: "row", gap: space.x3, minHeight: 44 },
  rail: { alignItems: "center", width: 24 },
  dot: {
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: colors.neutral100,
    borderWidth: 1,
    borderColor: colors.neutral300,
    alignItems: "center",
    justifyContent: "center",
  },
  dotDone: { backgroundColor: colors.success, borderColor: colors.success },
  dotCurrent: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  dotAttention: { backgroundColor: colors.warning, borderColor: colors.warning },
  line: { flex: 1, width: 2, backgroundColor: colors.neutral200, marginVertical: 2 },
  lineDone: { backgroundColor: colors.success },
  copy: { flex: 1, paddingBottom: space.x3 },
  label: { ...type.label, color: colors.navy950, paddingTop: 2 },
  muted: { color: colors.neutral600, fontFamily: "Inter_500Medium" },
  state: { ...type.meta, color: colors.blue700 },
  attention: { color: colors.warningText },
});
