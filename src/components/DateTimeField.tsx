import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronLeft, ChevronRight, Minus, Plus } from "lucide-react-native";
import { colors, radius, space, type } from "@/theme/tokens";

/** Cameroon (Africa/Douala) is UTC+01:00 all year, no DST. */
const OFFSET_MINUTES = 60;
const pad = (n: number) => String(n).padStart(2, "0");

/** Wall-clock parts in Douala for an epoch ms. */
const parts = (ms: number) => {
  const d = new Date(ms + OFFSET_MINUTES * 60_000);
  return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), h: d.getUTCHours(), min: d.getUTCMinutes() };
};
/** ISO 8601 with the Cameroon offset, as the claims API expects. */
export const toCameroonIso = (ms: number) => {
  const p = parts(ms);
  return `${p.y}-${pad(p.m)}-${pad(p.d)}T${pad(p.h)}:${pad(p.min)}:00+01:00`;
};

type Labels = {
  date: string;
  time: string;
  today: string;
  yesterday: string;
  previousDay: string;
  nextDay: string;
  earlier: string;
  later: string;
  hour: string;
  minutes: string;
};

/**
 * Dependency-free date + time picker (no native picker module is bundled):
 * day stepper with Today / Yesterday shortcuts and hour / 5-minute steppers.
 * Never allows a moment in the future when `maxNow` is set.
 */
export function DateTimeField({
  label,
  value,
  onChange,
  labels,
  language,
  maxNow = true,
}: {
  label: string;
  value: number;
  onChange: (ms: number) => void;
  labels: Labels;
  language: "en" | "fr";
  maxNow?: boolean;
}) {
  const set = (ms: number) => onChange(maxNow ? Math.min(ms, Date.now()) : ms);
  const DAY = 86_400_000;
  const now = Date.now();
  const dayIndex = (ms: number) => Math.floor((ms + OFFSET_MINUTES * 60_000) / DAY);
  const sameDay = (a: number, b: number) => dayIndex(a) === dayIndex(b);
  const dayText = new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "Africa/Douala",
  }).format(new Date(value));
  const p = parts(value);
  const Step = ({ a11y, onPress, icon: Icon, disabled }: { a11y: string; onPress: () => void; icon: typeof Plus; disabled?: boolean }) => (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={a11y}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      style={[styles.step, disabled && styles.disabled]}
    >
      <Icon size={18} color={colors.navy950} />
    </Pressable>
  );
  const Chip = ({ text, on, onPress }: { text: string; on: boolean; onPress: () => void }) => (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected: on }}
      onPress={onPress}
      style={[styles.chip, on && styles.chipOn]}
    >
      <Text style={[styles.chipText, on && styles.chipTextOn]}>{text}</Text>
    </Pressable>
  );
  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.chips}>
        <Chip text={labels.today} on={sameDay(value, now)} onPress={() => set(value + (dayIndex(now) - dayIndex(value)) * DAY)} />
        <Chip text={labels.yesterday} on={sameDay(value, now - DAY)} onPress={() => set(value + (dayIndex(now) - 1 - dayIndex(value)) * DAY)} />
      </View>
      <Text style={styles.caption}>{labels.date}</Text>
      <View style={styles.row}>
        <Step a11y={labels.previousDay} icon={ChevronLeft} onPress={() => set(value - DAY)} />
        <Text style={styles.value} accessibilityLiveRegion="polite">{dayText}</Text>
        <Step a11y={labels.nextDay} icon={ChevronRight} disabled={maxNow && value + DAY > now} onPress={() => set(value + DAY)} />
      </View>
      <Text style={styles.caption}>{labels.time}</Text>
      <View style={styles.row}>
        <Step a11y={`${labels.earlier} ${labels.hour}`} icon={Minus} onPress={() => set(value - 3_600_000)} />
        <Text style={styles.time} accessibilityLabel={`${pad(p.h)}:${pad(p.min)}`}>{pad(p.h)}</Text>
        <Step a11y={`${labels.later} ${labels.hour}`} icon={Plus} disabled={maxNow && value + 3_600_000 > now} onPress={() => set(value + 3_600_000)} />
        <Text style={styles.colon}>:</Text>
        <Step a11y={`${labels.earlier} ${labels.minutes}`} icon={Minus} onPress={() => set(value - 300_000)} />
        <Text style={styles.time}>{pad(p.min)}</Text>
        <Step a11y={`${labels.later} ${labels.minutes}`} icon={Plus} disabled={maxNow && value + 300_000 > now} onPress={() => set(value + 300_000)} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    gap: space.x2,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
  },
  label: { ...type.label, color: colors.navy950 },
  caption: { ...type.caption, color: colors.neutral600, marginTop: space.x1 },
  chips: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  chip: {
    minHeight: 44,
    paddingHorizontal: space.x4,
    justifyContent: "center",
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.neutral300,
  },
  chipOn: { backgroundColor: colors.blue50, borderColor: colors.blue600 },
  chipText: { ...type.label, color: colors.navy950 },
  chipTextOn: { color: colors.blue700 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  step: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    borderWidth: 1,
    borderColor: colors.neutral300,
    alignItems: "center",
    justifyContent: "center",
  },
  disabled: { opacity: 0.4 },
  value: { ...type.label, color: colors.navy950, flex: 1, textAlign: "center" },
  time: { ...type.cardTitle, color: colors.navy950, minWidth: 30, textAlign: "center", fontVariant: ["tabular-nums"] },
  colon: { ...type.cardTitle, color: colors.navy950 },
});
